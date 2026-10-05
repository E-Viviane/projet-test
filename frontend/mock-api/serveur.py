#!/usr/bin/env python3
"""Fausse API Helpdesk (stdlib uniquement) qui respecte le contrat de l'API Laravel `api-rest-complete`.
Sert à développer/tester l'interface Vue sans PHP ni MySQL. Données en mémoire : `POST /__reset` les remet à zéro.
Usage : python3 serveur.py [port]   (défaut 8000)"""
import json, re, sys, time, secrets, csv, io
from datetime import datetime, timedelta, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

TRANSITIONS = {'nouveau': ['en_cours', 'ferme'], 'en_cours': ['en_attente', 'resolu'], 'en_attente': ['en_cours', 'resolu'],
               'resolu': ['ferme', 'en_cours'], 'ferme': []}
PRIO_ORDRE = {'basse': 1, 'normale': 2, 'haute': 3, 'urgente': 4}
S = {}

def now(): return datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%S.000000Z')
def jours(n): return (datetime.now(timezone.utc) - timedelta(days=n)).strftime('%Y-%m-%dT%H:%M:%S.000000Z')

def reset():
    S.clear()
    S.update(users=[], tickets=[], tags=[], comments=[], pj=[], tokens={}, seq=dict(u=0, t=0, tag=0, c=0, pj=0), essais={}, idem={})
    for n, e, r in [('Admin Démo', 'admin@demo.test', 'admin'), ('Agent Démo', 'agent@demo.test', 'agent'), ('Agent Deux', 'agent2@demo.test', 'agent'),
                    ('Utilisateur Démo', 'user@demo.test', 'user'), ('Utilisateur Deux', 'user2@demo.test', 'user')]:
        creer_user(n, e, 'password', r)
    for nom, coul in [('bug', '#c2410c'), ('facturation', '#1d4ed8'), ('accès', '#7c3aed')]:
        S['seq']['tag'] += 1
        S['tags'].append(dict(id=S['seq']['tag'], nom=nom, couleur=coul))
    exemples = [('Impossible de me connecter', 'nouveau', 'haute', 4, 1, None, [1]), ('Facture en double', 'en_cours', 'normale', 4, 2, 2, [2]),
                ('Demande de nouveau compte', 'en_attente', 'basse', 5, 3, 3, [3]), ('Page blanche sur le tableau de bord', 'resolu', 'urgente', 4, 5, 2, [1]),
                ('Question sur mon abonnement', 'ferme', 'normale', 5, 9, 3, []), ('Export CSV vide', 'en_cours', 'haute', 4, 1, 2, [1])]
    for i, (t, st, pr, a, age, ag, tg) in enumerate(exemples):
        S['seq']['t'] += 1
        S['tickets'].append(dict(id=S['seq']['t'], titre=t, description=f'Description détaillée du ticket « {t} ».', statut=st, priorite=pr, echeance=(datetime.now() - timedelta(days=1 if i == 5 else -5)).strftime('%Y-%m-%d'),
                                 auteur_id=a, agent_id=ag, tags=tg, resolu_le=jours(age) if st in ('resolu', 'ferme') else None, cree_le=jours(age), modifie_le=jours(age), supprime_le=None))
    for i in range(1, 31):  # volume pour tester la pagination
        S['seq']['t'] += 1
        S['tickets'].append(dict(id=S['seq']['t'], titre=f'Ticket de test n°{i}', description='Données de remplissage.', statut='nouveau', priorite=['basse', 'normale', 'haute'][i % 3],
                                 echeance=None, auteur_id=4, agent_id=None, tags=[], resolu_le=None, cree_le=jours(i % 20), modifie_le=jours(i % 20), supprime_le=None))
    S['seq']['c'] += 1
    S['comments'].append(dict(id=1, ticket_id=2, auteur_id=2, contenu='Je regarde la facture.', interne=False, cree_le=jours(1)))
    S['seq']['c'] += 1
    S['comments'].append(dict(id=2, ticket_id=2, auteur_id=2, contenu='Note : client déjà remboursé une fois.', interne=True, cree_le=jours(1)))

def creer_user(nom, email, pw, role):
    S['seq']['u'] += 1
    u = dict(id=S['seq']['u'], name=nom, email=email, password=pw, role=role, cree_le=now())
    S['users'].append(u)
    return u

class Err(Exception):
    def __init__(s, status, code, message, errors=None, extra=None, headers=None):
        s.status, s.body, s.headers = status, dict(message=message, code=code, **({'errors': errors} if errors else {}), **(extra or {})), headers or {}

def u_pub(u): return dict(id=u['id'], name=u['name'], email=u['email'], role=u['role'], cree_le=u['cree_le'])
def user(i): return next((u for u in S['users'] if u['id'] == i), None)
def mini(i):
    u = user(i); return dict(id=u['id'], name=u['name']) if u else None
def capacites(role): return ['tickets:lire', 'tickets:ecrire'] + (['tickets:gerer'] if role in ('agent', 'admin') else []) + (['admin'] if role == 'admin' else [])

def peut_voir(u, t): return u['role'] in ('agent', 'admin') or t['auteur_id'] == u['id']
def peut_modifier(u, t): return u['role'] in ('agent', 'admin') or (t['auteur_id'] == u['id'] and t['statut'] == 'nouveau')
def peut_supprimer(u, t): return u['role'] == 'admin' or (t['auteur_id'] == u['id'] and t['statut'] == 'nouveau')

def ticket_json(t, u, inclure=()):
    en_retard = bool(t['echeance'] and t['statut'] not in ('resolu', 'ferme') and t['echeance'] < datetime.now().strftime('%Y-%m-%d'))
    nb = len([c for c in S['comments'] if c['ticket_id'] == t['id'] and (u['role'] != 'user' or not c['interne'])])
    return dict(id=t['id'], reference=f"TCK-{t['id']:05d}", titre=t['titre'], description=t['description'], statut=t['statut'], priorite=t['priorite'],
                echeance=t['echeance'], en_retard=en_retard, resolu_le=t['resolu_le'], cree_le=t['cree_le'], modifie_le=t['modifie_le'], supprime_le=t['supprime_le'],
                auteur=mini(t['auteur_id']), agent=mini(t['agent_id']) if t['agent_id'] else None, tags=[g for g in S['tags'] if g['id'] in t['tags']],
                pieces_jointes=[pj_json(p) for p in S['pj'] if p['ticket_id'] == t['id']], commentaires_count=nb,
                permissions=dict(modifier=peut_modifier(u, t), supprimer=peut_supprimer(u, t)), liens=dict(self=f"/api/v1/tickets/{t['id']}"))

def pj_json(p): return dict(id=p['id'], nom=p['nom'], taille=len(p['contenu']), type=p['type'], cree_le=p['cree_le'])

def paginer(items, q, f):
    pp = max(1, min(100, int(q.get('per_page', ['15'])[0]))); page = max(1, int(q.get('page', ['1'])[0]))
    total = len(items); dernier = max(1, -(-total // pp)); sl = items[(page - 1) * pp: page * pp]
    return dict(data=[f(x) for x in sl], links=dict(first='?page=1', last=f'?page={dernier}', prev=None, next=None),
                meta=dict(current_page=page, last_page=dernier, per_page=pp, total=total, from_=(page - 1) * pp + 1 if sl else None, to=(page - 1) * pp + len(sl) if sl else None))

def fixe_meta(r):
    r['meta']['from'] = r['meta'].pop('from_'); return r

def requis(d, champs):
    err = {c: ['Ce champ est obligatoire.'] for c in champs if d.get(c) in (None, '')}
    if err: raise Err(422, 'validation_echouee', 'Les données fournies sont invalides.', err)

def valider_ticket(d, complet):
    err = {}
    if complet or 'titre' in d:
        t = (d.get('titre') or '').strip()
        if not t: err['titre'] = ['Le titre est obligatoire.']
        elif len(t) < 3: err['titre'] = ['Le titre doit contenir au moins 3 caractères.']
    if complet or 'description' in d:
        if not (d.get('description') or '').strip(): err['description'] = ['La description est obligatoire.']
    if d.get('priorite') and d['priorite'] not in PRIO_ORDRE: err['priorite'] = ['La priorité est invalide.']
    if d.get('echeance') and not re.match(r'^\d{4}-\d{2}-\d{2}$', d['echeance']): err['echeance'] = ["La date d'échéance est invalide."]
    if err: raise Err(422, 'validation_echouee', 'Les données fournies sont invalides.', err)

def parse_multipart(ctype, body):
    m = re.search(r'boundary=(.+)', ctype); sep = b'--' + m.group(1).strip('"').encode()
    for part in body.split(sep):
        if b'filename=' in part:
            tete, _, contenu = part.partition(b'\r\n\r\n')
            nom = re.search(rb'filename="([^"]*)"', tete).group(1).decode()
            t = re.search(rb'Content-Type: ([^\r\n]+)', tete)
            return nom, (t.group(1).decode() if t else 'application/octet-stream'), contenu[:-2]
    return None, None, None

class H(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'
    def log_message(self, *a): pass

    def envoyer(self, status, corps=None, headers=None, brut=None, ctype='application/json'):
        data = brut if brut is not None else (b'' if corps is None else json.dumps(corps, ensure_ascii=False).encode())
        self.send_response(status)
        if status != 204:
            self.send_header('Content-Type', ctype); self.send_header('Content-Length', str(len(data)))
        else: self.send_header('Content-Length', '0')
        self.send_header('X-Request-Id', secrets.token_hex(8))
        for k, v in (headers or {}).items(): self.send_header(k, v)
        self.end_headers()
        if status != 204: self.wfile.write(data)

    def traiter(self, methode):
        p = urlparse(self.path); q = parse_qs(p.query)
        n = int(self.headers.get('Content-Length') or 0); brut = self.rfile.read(n) if n else b''
        ctype = self.headers.get('Content-Type', '')
        try:
            if p.path == '/__reset': reset(); return self.envoyer(204)
            if not p.path.startswith('/api/v1/'): raise Err(404, 'introuvable', 'Ressource introuvable.')
            corps = {}
            if brut and 'json' in ctype:
                try: corps = json.loads(brut)
                except ValueError: raise Err(400, 'json_invalide', 'Le corps de la requête n’est pas du JSON valide.')
            time.sleep(0.05)
            r = self.router(methode, p.path[8:].strip('/'), q, corps, brut, ctype)
            if r is None: return self.envoyer(204)
            status, data, *rest = r
            self.envoyer(status, data, **(rest[0] if rest else {}))
        except Err as e:
            self.envoyer(e.status, e.body, e.headers)
        except Exception as e:  # pragma: no cover
            import traceback; traceback.print_exc()
            self.envoyer(500, dict(message='Erreur interne.', code='erreur_serveur'))

    do_GET = lambda s: s.traiter('GET'); do_POST = lambda s: s.traiter('POST'); do_PUT = lambda s: s.traiter('PUT')
    do_PATCH = lambda s: s.traiter('PATCH'); do_DELETE = lambda s: s.traiter('DELETE')

    def authentifier(self):
        h = self.headers.get('Authorization', '')
        tok = h[7:] if h.startswith('Bearer ') else None
        info = S['tokens'].get(tok)
        if not info or info['expire'] < time.time(): raise Err(401, 'non_authentifie', 'Non authentifié.')
        info['derniere'] = now()
        return user(info['uid']), tok, info

    def ouvrir_session(self, u, nom='navigateur'):
        tok = secrets.token_hex(20); S['seq'].setdefault('j', 0); S['seq']['j'] += 1
        exp = time.time() + 8 * 3600
        S['tokens'][tok] = dict(id=S['seq']['j'], uid=u['id'], nom=nom, expire=exp, derniere=None)
        return dict(data=u_pub(u), token=tok, type='Bearer', capacites=capacites(u['role']), expire_le=datetime.fromtimestamp(exp, timezone.utc).strftime('%Y-%m-%dT%H:%M:%S.000000Z'))

    def router(self, m, chemin, q, d, brut, ctype):
        seg = chemin.split('/')
        # ---------- authentification (publique) ----------
        if chemin == 'auth/connexion' and m == 'POST':
            ip = 'ip'; essais = [t for t in S['essais'].get(ip, []) if t > time.time() - 60]
            if len(essais) >= 5: raise Err(429, 'trop_de_requetes', 'Trop de tentatives. Réessayez plus tard.', headers={'Retry-After': '30'})
            S['essais'][ip] = essais + [time.time()]
            requis(d, ['email', 'password'])
            u = next((x for x in S['users'] if x['email'] == d['email'].strip().lower() and x['password'] == d['password']), None)
            if not u: raise Err(401, 'identifiants_invalides', 'Identifiants incorrects.')
            S['essais'][ip] = []
            return 200, self.ouvrir_session(u)
        if chemin == 'auth/inscription' and m == 'POST':
            err = {}
            if not (d.get('name') or '').strip(): err['name'] = ['Le nom est obligatoire.']
            e = (d.get('email') or '').strip().lower()
            if not re.match(r'^[^@\s]+@[^@\s]+\.[^@\s]+$', e): err['email'] = ["L'adresse e-mail est invalide."]
            elif any(x['email'] == e for x in S['users']): err['email'] = ['Cette adresse e-mail est déjà utilisée.']
            pw = d.get('password') or ''
            if len(pw) < 8 or not re.search(r'[A-Za-z]', pw) or not re.search(r'\d', pw): err['password'] = ['Le mot de passe doit contenir au moins 8 caractères, avec une lettre et un chiffre.']
            elif pw != d.get('password_confirmation'): err['password'] = ['La confirmation ne correspond pas.']
            if err: raise Err(422, 'validation_echouee', 'Les données fournies sont invalides.', err)
            return 201, self.ouvrir_session(creer_user(d['name'].strip(), e, pw, 'user'))
        # ---------- tout le reste exige un jeton ----------
        moi, tok, info = self.authentifier()
        agent = moi['role'] in ('agent', 'admin'); admin = moi['role'] == 'admin'
        def exiger(ok):
            if not ok: raise Err(403, 'interdit', "Vous n'avez pas le droit d'effectuer cette action.")
        if chemin == 'auth/moi':
            if m == 'GET': return 200, dict(data=u_pub(moi))
            if m == 'PATCH':
                err = {}
                if 'name' in d and not (d['name'] or '').strip(): err['name'] = ['Le nom est obligatoire.']
                if 'email' in d:
                    e = (d['email'] or '').strip().lower()
                    if not re.match(r'^[^@\s]+@[^@\s]+\.[^@\s]+$', e): err['email'] = ["L'adresse e-mail est invalide."]
                    elif any(x['email'] == e and x['id'] != moi['id'] for x in S['users']): err['email'] = ['Cette adresse e-mail est déjà utilisée.']
                if err: raise Err(422, 'validation_echouee', 'Les données fournies sont invalides.', err)
                if 'name' in d: moi['name'] = d['name'].strip()
                if 'email' in d: moi['email'] = d['email'].strip().lower()
                return 200, dict(data=u_pub(moi))
        if chemin == 'auth/mot-de-passe' and m == 'PUT':
            err = {}
            if d.get('mot_de_passe_actuel') != moi['password']: err['mot_de_passe_actuel'] = ['Le mot de passe actuel est incorrect.']
            pw = d.get('password') or ''
            if len(pw) < 8 or not re.search(r'[A-Za-z]', pw) or not re.search(r'\d', pw): err['password'] = ['Le mot de passe doit contenir au moins 8 caractères, avec une lettre et un chiffre.']
            elif pw != d.get('password_confirmation'): err['password'] = ['La confirmation ne correspond pas.']
            if err: raise Err(422, 'validation_echouee', 'Les données fournies sont invalides.', err)
            moi['password'] = pw
            for k in [k for k, v in S['tokens'].items() if v['uid'] == moi['id'] and k != tok]: del S['tokens'][k]
            return None
        if chemin == 'auth/jetons' and m == 'GET':
            return 200, dict(data=[dict(id=v['id'], nom=v['nom'], capacites=capacites(moi['role']), derniere_utilisation=v['derniere'],
                                        expire_le=datetime.fromtimestamp(v['expire'], timezone.utc).strftime('%Y-%m-%dT%H:%M:%S.000000Z'), courant=k == tok)
                                   for k, v in S['tokens'].items() if v['uid'] == moi['id']])
        if seg[:2] == ['auth', 'jetons'] and len(seg) == 3 and m == 'DELETE':
            k = next((k for k, v in S['tokens'].items() if v['uid'] == moi['id'] and v['id'] == int(seg[2])), None)
            if not k: raise Err(404, 'introuvable', 'Ressource introuvable.')
            del S['tokens'][k]; return None
        if chemin == 'auth/deconnexion' and m == 'POST': del S['tokens'][tok]; return None
        if chemin == 'auth/deconnexion-totale' and m == 'POST':
            for k in [k for k, v in S['tokens'].items() if v['uid'] == moi['id']]: del S['tokens'][k]
            return None
        # ---------- agents / statistiques ----------
        if chemin == 'agents' and m == 'GET':
            exiger(agent); return 200, dict(data=[mini(u['id']) for u in S['users'] if u['role'] in ('agent', 'admin')])
        if chemin == 'statistiques' and m == 'GET':
            exiger(agent); ts = [t for t in S['tickets'] if not t['supprime_le']]
            tj = lambda t: ticket_json(t, moi)
            par_statut = {s: len([t for t in ts if t['statut'] == s]) for s in TRANSITIONS}
            par_prio = {p: len([t for t in ts if t['priorite'] == p]) for p in PRIO_ORDRE}
            jrs = {}
            for t in ts: jrs[t['cree_le'][:10]] = jrs.get(t['cree_le'][:10], 0) + 1
            charge = {}
            for t in ts:
                if t['agent_id'] and t['statut'] not in ('resolu', 'ferme'): charge[t['agent_id']] = charge.get(t['agent_id'], 0) + 1
            return 200, dict(data=dict(total=len(ts), par_statut=par_statut, par_priorite=par_prio, en_retard=len([t for t in ts if tj(t)['en_retard']]),
                                       crees_30_jours=[dict(jour=j, total=n) for j, n in sorted(jrs.items())],
                                       charge_agents=[dict(agent=user(i)['name'], tickets_ouverts=n) for i, n in charge.items()]))
        # ---------- tags ----------
        if seg[0] == 'tags':
            def tag_json(g): return dict(id=g['id'], nom=g['nom'], couleur=g['couleur'], tickets_count=len([t for t in S['tickets'] if g['id'] in t['tags'] and not t['supprime_le']]))
            if len(seg) == 1 and m == 'GET': return 200, dict(data=[tag_json(g) for g in S['tags']])
            if len(seg) == 1 and m == 'POST':
                exiger(agent); self.valider_tag(d, None); S['seq']['tag'] += 1
                g = dict(id=S['seq']['tag'], nom=d['nom'].strip(), couleur=d.get('couleur') or '#475569'); S['tags'].append(g); return 201, dict(data=tag_json(g))
            g = next((g for g in S['tags'] if len(seg) > 1 and str(g['id']) == seg[1]), None)
            if len(seg) == 2:
                if not g: raise Err(404, 'introuvable', 'Ressource introuvable.')
                if m == 'GET': return 200, dict(data=tag_json(g))
                if m in ('PATCH', 'PUT'):
                    exiger(agent); self.valider_tag(d, g['id']); g['nom'] = d.get('nom', g['nom']).strip(); g['couleur'] = d.get('couleur', g['couleur']); return 200, dict(data=tag_json(g))
                if m == 'DELETE':
                    exiger(admin); S['tags'].remove(g)
                    for t in S['tickets']: t['tags'] = [i for i in t['tags'] if i != g['id']]
                    return None
        # ---------- utilisateurs (admin) ----------
        if seg[0] == 'utilisateurs':
            exiger(admin)
            def uj(u): return dict(u_pub(u), tickets_count=len([t for t in S['tickets'] if t['auteur_id'] == u['id']]))
            if len(seg) == 1 and m == 'GET':
                its = S['users']
                if q.get('q', [''])[0]: s = q['q'][0].lower(); its = [u for u in its if s in u['name'].lower() or s in u['email'].lower()]
                if q.get('role', [''])[0]: its = [u for u in its if u['role'] == q['role'][0]]
                return 200, fixe_meta(paginer(its, q, uj))
            u = next((u for u in S['users'] if len(seg) > 1 and str(u['id']) == seg[1]), None)
            if not u: raise Err(404, 'introuvable', 'Ressource introuvable.')
            if m == 'GET': return 200, dict(data=uj(u))
            if m == 'PATCH':
                if u['id'] == moi['id']: raise Err(409, 'auto_modification', 'Vous ne pouvez pas modifier votre propre rôle.')
                if d.get('role') not in ('user', 'agent', 'admin'): raise Err(422, 'validation_echouee', 'Les données fournies sont invalides.', {'role': ['Le rôle est invalide.']})
                u['role'] = d['role']; return 200, dict(data=uj(u))
            if m == 'DELETE':
                if u['id'] == moi['id']: raise Err(409, 'auto_suppression', 'Vous ne pouvez pas supprimer votre propre compte.')
                if any(t['auteur_id'] == u['id'] for t in S['tickets']) or any(c['auteur_id'] == u['id'] for c in S['comments']):
                    raise Err(409, 'utilisateur_lie', 'Ce compte est lié à des tickets ou des commentaires et ne peut pas être supprimé.')
                S['users'].remove(u); return None
        # ---------- pièces jointes (accès direct) ----------
        if seg[0] == 'pieces-jointes' and len(seg) == 2:
            p = next((p for p in S['pj'] if str(p['id']) == seg[1]), None)
            t = p and next(t for t in S['tickets'] if t['id'] == p['ticket_id'])
            if not p or not peut_voir(moi, t): raise Err(404, 'introuvable', 'Ressource introuvable.')
            if m == 'GET':
                return 200, None, dict(brut=p['contenu'], ctype=p['type'], headers={'Content-Disposition': f'attachment; filename="{p["nom"]}"'})
            if m == 'DELETE': exiger(peut_modifier(moi, t)); S['pj'].remove(p); return None
        # ---------- tickets ----------
        if seg[0] == 'tickets':
            return self.tickets(m, seg, q, d, brut, ctype, moi, agent, admin, exiger)
        raise Err(404, 'introuvable', 'Ressource introuvable.')

    def valider_tag(self, d, ident):
        err = {}
        nom = (d.get('nom') or '').strip()
        if not nom: err['nom'] = ['Le nom est obligatoire.']
        elif any(g['nom'].lower() == nom.lower() and g['id'] != ident for g in S['tags']): err['nom'] = ['Ce tag existe déjà.']
        if d.get('couleur') and not re.match(r'^#[0-9a-fA-F]{6}$', d['couleur']): err['couleur'] = ['La couleur doit être au format #rrggbb.']
        if err: raise Err(422, 'validation_echouee', 'Les données fournies sont invalides.', err)

    def tickets(self, m, seg, q, d, brut, ctype, moi, agent, admin, exiger):
        def get_ticket(i, corbeille=False):
            t = next((t for t in S['tickets'] if str(t['id']) == i), None)
            if not t or bool(t['supprime_le']) != corbeille or not peut_voir(moi, t): raise Err(404, 'introuvable', 'Ressource introuvable.')
            return t
        tj = lambda t: ticket_json(t, moi)
        def filtrer(corb):
            its = [t for t in S['tickets'] if bool(t['supprime_le']) == corb and peut_voir(moi, t)]
            if q.get('statut', [''])[0]: its = [t for t in its if t['statut'] in q['statut'][0].split(',')]
            if q.get('priorite', [''])[0]: its = [t for t in its if t['priorite'] == q['priorite'][0]]
            a = q.get('agent_id', [''])[0]
            if a == 'aucun': its = [t for t in its if not t['agent_id']]
            elif a: its = [t for t in its if str(t['agent_id']) == a]
            if q.get('q', [''])[0]: s = q['q'][0].lower(); its = [t for t in its if s in t['titre'].lower() or s in t['description'].lower() or s in f"tck-{t['id']:05d}".lower()]
            if q.get('en_retard', [''])[0] in ('1', 'true'): its = [t for t in its if tj(t)['en_retard']]
            for crit in reversed((q.get('tri', ['-created_at'])[0] or '-created_at').split(',')):
                desc = crit.startswith('-'); c = crit.lstrip('-')
                cle = {'priorite': lambda t: PRIO_ORDRE[t['priorite']], 'created_at': lambda t: t['cree_le'], 'echeance': lambda t: t['echeance'] or '9999',
                       'titre': lambda t: t['titre'].lower(), 'statut': lambda t: t['statut'], 'updated_at': lambda t: t['modifie_le']}.get(c, lambda t: t['id'])
                its.sort(key=cle, reverse=desc)
            return its
        if len(seg) == 1:
            if m == 'GET': return 200, fixe_meta(paginer(filtrer(False), q, tj))
            if m == 'POST':
                cle = self.headers.get('Idempotency-Key')
                if cle and cle in S['idem']: return 201, S['idem'][cle]
                valider_ticket(d, True)
                S['seq']['t'] += 1
                t = dict(id=S['seq']['t'], titre=d['titre'].strip(), description=d['description'].strip(), statut='nouveau', priorite=d.get('priorite') or 'normale', echeance=d.get('echeance') or None,
                         auteur_id=moi['id'], agent_id=None, tags=[i for i in d.get('tags', []) if any(g['id'] == i for g in S['tags'])], resolu_le=None, cree_le=now(), modifie_le=now(), supprime_le=None)
                S['tickets'].append(t); rep = dict(data=tj(t))
                if cle: S['idem'][cle] = rep
                return 201, rep
        if seg[1] == 'export' and m == 'GET':
            exiger(agent); buf = io.StringIO(); w = csv.writer(buf, delimiter=';')
            w.writerow(['reference', 'titre', 'statut', 'priorite', 'agent', 'cree_le'])
            for t in filtrer(False): w.writerow([f"TCK-{t['id']:05d}", t['titre'], t['statut'], t['priorite'], (user(t['agent_id']) or {}).get('name', ''), t['cree_le']])
            return 200, None, dict(brut=('﻿' + buf.getvalue()).encode(), ctype='text/csv; charset=utf-8', headers={'Content-Disposition': 'attachment; filename="tickets.csv"'})
        if seg[1] == 'corbeille' and m == 'GET':
            exiger(agent); return 200, fixe_meta(paginer(filtrer(True), q, tj))
        if len(seg) == 2:
            if m == 'GET': return 200, dict(data=tj(get_ticket(seg[1])))
            if m in ('PATCH', 'PUT'):
                t = get_ticket(seg[1]); exiger(peut_modifier(moi, t)); valider_ticket(d, m == 'PUT')
                for k in ('titre', 'description', 'priorite', 'echeance'):
                    if k in d: t[k] = (d[k].strip() if isinstance(d[k], str) else d[k]) or (None if k == 'echeance' else t[k])
                t['modifie_le'] = now(); return 200, dict(data=tj(t))
            if m == 'DELETE':
                t = get_ticket(seg[1]); exiger(peut_supprimer(moi, t)); t['supprime_le'] = now(); return None
        t = get_ticket(seg[1], corbeille=len(seg) > 2 and seg[2] in ('restauration', 'definitif')) if len(seg) > 2 else None
        sous = seg[2]
        if sous == 'restauration' and m == 'POST': exiger(agent); t['supprime_le'] = None; return 200, dict(data=tj(t))
        if sous == 'definitif' and m == 'DELETE': exiger(admin); S['tickets'].remove(t); return None
        if sous == 'transitions' and m == 'POST':
            exiger(agent); nv = d.get('statut')
            if nv not in TRANSITIONS: raise Err(422, 'validation_echouee', 'Les données fournies sont invalides.', {'statut': ['Le statut est invalide.']})
            if nv not in TRANSITIONS[t['statut']]:
                raise Err(409, 'transition_interdite', f"Passage de « {t['statut']} » à « {nv} » impossible.", extra=dict(statut_actuel=t['statut'], transitions_permises=TRANSITIONS[t['statut']]))
            t['statut'] = nv; t['resolu_le'] = now() if nv in ('resolu', 'ferme') else None; t['modifie_le'] = now(); return 200, dict(data=tj(t))
        if sous == 'assignation':
            exiger(agent)
            if m == 'POST':
                a = user(d.get('agent_id') if isinstance(d.get('agent_id'), int) else -1)
                if not a or a['role'] not in ('agent', 'admin'): raise Err(422, 'validation_echouee', 'Les données fournies sont invalides.', {'agent_id': ["Cet utilisateur n'est pas un agent."]})
                t['agent_id'] = a['id']
            else: t['agent_id'] = None
            t['modifie_le'] = now(); return 200, dict(data=tj(t))
        if sous == 'tags' and m == 'PUT':
            exiger(peut_modifier(moi, t)); ids = d.get('tags')
            if not isinstance(ids, list) or any(not any(g['id'] == i for g in S['tags']) for i in ids): raise Err(422, 'validation_echouee', 'Les données fournies sont invalides.', {'tags': ['Un des tags est invalide.']})
            t['tags'] = ids; return 200, dict(data=tj(t))
        if sous == 'commentaires':
            def cj(c): return dict(id=c['id'], contenu=c['contenu'], interne=c['interne'], auteur=mini(c['auteur_id']), cree_le=c['cree_le'])
            if m == 'GET':
                cs = [c for c in S['comments'] if c['ticket_id'] == t['id'] and (agent or not c['interne'])]
                return 200, fixe_meta(paginer(cs, q, cj))
            if m == 'POST':
                if t['statut'] == 'ferme': raise Err(409, 'ticket_ferme', 'Ce ticket est fermé : les commentaires sont désactivés.')
                c = (d.get('contenu') or '').strip()
                if not c: raise Err(422, 'validation_echouee', 'Les données fournies sont invalides.', {'contenu': ['Le commentaire est obligatoire.']})
                S['seq']['c'] += 1; nc = dict(id=S['seq']['c'], ticket_id=t['id'], auteur_id=moi['id'], contenu=c, interne=bool(d.get('interne')) and agent, cree_le=now())
                S['comments'].append(nc); return 201, dict(data=cj(nc))
            if m == 'DELETE' and len(seg) == 4:
                c = next((c for c in S['comments'] if str(c['id']) == seg[3] and c['ticket_id'] == t['id']), None)
                if not c: raise Err(404, 'introuvable', 'Ressource introuvable.')
                exiger(moi['role'] == 'admin' or c['auteur_id'] == moi['id']); S['comments'].remove(c); return None
        if sous == 'pieces-jointes' and m == 'POST':
            exiger(peut_modifier(moi, t) or agent)
            nom, typ, contenu = parse_multipart(ctype, brut)
            if nom is None: raise Err(422, 'validation_echouee', 'Les données fournies sont invalides.', {'fichier': ['Le fichier est obligatoire.']})
            ext = nom.rsplit('.', 1)[-1].lower() if '.' in nom else ''
            if ext not in ('pdf', 'png', 'jpg', 'jpeg', 'txt', 'docx', 'xlsx'): raise Err(422, 'validation_echouee', 'Les données fournies sont invalides.', {'fichier': ['Ce type de fichier n’est pas autorisé.']})
            if len(contenu) > 5 * 1024 * 1024: raise Err(422, 'validation_echouee', 'Les données fournies sont invalides.', {'fichier': ['Le fichier dépasse 5 Mo.']})
            S['seq']['pj'] += 1; p = dict(id=S['seq']['pj'], ticket_id=t['id'], nom=nom, type=typ, contenu=contenu, cree_le=now()); S['pj'].append(p)
            return 201, dict(data=pj_json(p))
        raise Err(404, 'introuvable', 'Ressource introuvable.')

if __name__ == '__main__':
    reset()
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8000
    ThreadingHTTPServer(('127.0.0.1', port), H).serve_forever()
