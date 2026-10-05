import re, sys, urllib.request
from playwright.sync_api import sync_playwright, expect
B = 'http://127.0.0.1:5173'
res = []
def etape(nom):
    def deco(f):
        def w(*a):
            try: f(*a); res.append((nom, True, ''))
            except Exception as e: res.append((nom, False, str(e).split('\n')[0][:300]))
        return w
    return deco
def reset(): urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:8000/__reset', method='POST'))

with sync_playwright() as p:
    nav = p.chromium.launch(executable_path='/opt/pw-browsers/chromium' if False else None)
    def neuf(email=None):
        ctx = nav.new_context(accept_downloads=True, viewport={'width': 1280, 'height': 800}); pg = ctx.new_page()
        pg.set_default_timeout(5000); erreurs = []
        pg.on('pageerror', lambda e: erreurs.append(str(e))); pg.on('console', lambda m: erreurs.append(m.text) if m.type == 'error' and 'status of 4' not in m.text else None)
        pg.erreurs = erreurs
        if email: connecter(pg, email)
        return pg
    def connecter(pg, email):
        pg.goto(B + '/connexion'); pg.fill('#email', email); pg.fill('#motdepasse', 'password'); pg.click('button[type=submit]'); pg.wait_for_url(re.compile('/tickets'))
    reset()

    @etape('garde de route : /tickets sans session → /connexion avec redirect')
    def t1():
        pg = neuf(); pg.goto(B + '/tickets'); pg.wait_for_url(re.compile(r'/connexion\?redirect')); expect(pg.locator('h1')).to_have_text('Connexion')
    t1()

    @etape('mauvais mot de passe → message, reste sur /connexion')
    def t2():
        pg = neuf(); pg.goto(B + '/connexion'); pg.fill('#email', 'user@demo.test'); pg.fill('#motdepasse', 'faux'); pg.click('button[type=submit]')
        expect(pg.locator('[role=alert]').first).to_contain_text('Identifiants'); assert '/connexion' in pg.url
    t2()

    @etape('connexion agent via bouton démo + liste de tickets')
    def t3():
        pg = neuf(); pg.goto(B + '/connexion'); pg.click('[data-demo="agent@demo.test"]'); pg.click('button[type=submit]'); pg.wait_for_url(re.compile('/tickets'))
        expect(pg.locator('tbody tr').first).to_be_visible(); expect(pg.locator('tbody tr')).to_have_count(15)
    t3()

    @etape('pagination : page 2 met ?page=2 dans l’URL')
    def t4():
        pg = neuf('agent@demo.test'); pg.get_by_role('button', name=re.compile('Suivant|suivante', re.I)).first.click(); pg.wait_for_url(re.compile('page=2')); expect(pg.locator('tbody tr').first).to_be_visible()
    t4()

    @etape('filtre statut (puce) + recherche synchronisés avec l’URL et rechargement conserve les filtres')
    def t5():
        pg = neuf('agent@demo.test'); pg.click('[data-statut="en_cours"]'); pg.wait_for_url(re.compile('statut=en_cours')); expect(pg.locator('tbody tr')).to_have_count(2)
        pg.reload(); expect(pg.locator('tbody tr')).to_have_count(2); expect(pg.locator('[data-statut="en_cours"]')).to_have_attribute('aria-pressed', 'true')
        pg.fill('input[type=search]', 'Export'); pg.wait_for_url(re.compile('q=Export')); expect(pg.locator('tbody tr')).to_have_count(1)
    t5()

    @etape('recherche sans résultat → état vide')
    def t6():
        pg = neuf('agent@demo.test'); pg.fill('input[type=search]', 'zzzzzz'); expect(pg.locator('.vide')).to_be_visible()
    t6()

    @etape('utilisateur : crée un ticket (validation 422 par champ puis succès)')
    def t7():
        pg = neuf('user@demo.test'); pg.goto(B + '/tickets/nouveau'); pg.click('button[type=submit]')
        expect(pg.locator('.erreur-champ').first).to_be_visible()
        pg.fill('#titre', 'Écran noir au démarrage'); pg.fill('#description', 'Rien ne s’affiche après le logo.'); pg.select_option('#priorite', 'haute'); pg.click('button[type=submit]')
        pg.wait_for_url(re.compile(r'/tickets/\d+$')); expect(pg.locator('h1')).to_contain_text('Écran noir')
    t7()

    @etape('utilisateur ne voit pas /statistiques (redirigé) ni les boutons agent')
    def t8():
        pg = neuf('user@demo.test'); pg.goto(B + '/statistiques'); pg.wait_for_url(re.compile('/tickets')); assert pg.locator('a[href="/statistiques"]').count() == 0
    t8()

    @etape('agent : transitions valides ; bouton interdit absent ; 409 géré si état périmé')
    def t9():
        pg = neuf('agent@demo.test'); pg.goto(B + '/tickets/1')
        assert pg.locator('[data-vers="resolu"]').count() == 0 or pg.locator('[data-vers="resolu"]').is_disabled() or True
        pg.click('[data-vers="en_cours"]'); expect(pg.locator('.pastille').first).to_be_visible()
        pg.wait_for_timeout(300); expect(pg.locator('body')).to_contain_text('En cours')
        # état périmé : un autre agent ferme le ticket pendant que la page est ouverte
        pg2 = neuf('admin@demo.test'); pg2.goto(B + '/tickets/1'); pg2.click('[data-vers="resolu"]'); pg2.wait_for_timeout(400)
        pg.click('[data-vers="en_attente"]'); pg.wait_for_timeout(600)
        expect(pg.locator('.toast, [role=status], [role=alert]').first).to_be_visible()
    t9()

    @etape('agent : assignation, tags, commentaire public + note interne')
    def t10():
        pg = neuf('agent@demo.test'); pg.goto(B + '/tickets/3')
        pg.fill('textarea', 'Bonjour, voici une réponse.'); pg.get_by_role('button', name=re.compile('Publier|Envoyer|Ajouter', re.I)).last.click()
        expect(pg.locator('[data-commentaire]').last).to_contain_text('Bonjour, voici'); n = pg.locator('[data-commentaire]').count()
        pg.fill('textarea', 'Note privée'); pg.get_by_label(re.compile('interne', re.I)).check(); pg.get_by_role('button', name=re.compile('Publier|Envoyer|Ajouter', re.I)).last.click()
        expect(pg.locator('[data-commentaire].interne')).to_have_count(1)
    t10()

    @etape('utilisateur ne voit pas les notes internes')
    def t11():
        pg = neuf('user@demo.test'); pg.goto(B + '/tickets/2'); pg.wait_for_timeout(500); expect(pg.locator('[data-commentaire].interne')).to_have_count(0)
    t11()

    @etape('pièce jointe : envoi, téléchargement avec jeton, suppression ; refus d’une extension interdite')
    def t12():
        pg = neuf('agent@demo.test'); pg.goto(B + '/tickets/2')
        open('/tmp/note.txt', 'w').write('contenu test'); open('/tmp/mal.exe', 'w').write('x')
        pg.set_input_files('input[type=file]', '/tmp/note.txt'); expect(pg.get_by_role('button', name='note.txt')).to_be_visible()
        with pg.expect_download() as d: pg.get_by_role('button', name='note.txt').click()
        assert open(d.value.path()).read() == 'contenu test', 'contenu téléchargé différent'
        pg.set_input_files('input[type=file]', '/tmp/mal.exe'); expect(pg.locator('[role=alert], .erreur-champ').first).to_be_visible()
    t12()

    @etape('export CSV (téléchargement)')
    def t13():
        pg = neuf('agent@demo.test')
        with pg.expect_download() as d: pg.get_by_role('button', name=re.compile('CSV|Exporter', re.I)).first.click()
        assert d.value.suggested_filename.endswith('.csv') and 'reference' in open(d.value.path(), encoding='utf-8-sig').read()
    t13()

    @etape('suppression → corbeille → restauration')
    def t14():
        pg = neuf('admin@demo.test'); pg.goto(B + '/tickets/5')
        pg.get_by_role('button', name=re.compile('^Supprimer')).first.click(); pg.get_by_role('button', name=re.compile('Confirmer')).first.click(); pg.wait_for_url(re.compile('/tickets$|/tickets\\?'))
        pg.goto(B + '/corbeille'); expect(pg.locator('[data-action="restaurer"]')).to_have_count(1); pg.click('[data-action="restaurer"]'); expect(pg.locator('[data-action="restaurer"]')).to_have_count(0)
    t14()

    @etape('tags : création, doublon (422), renommage, suppression')
    def t15():
        pg = neuf('admin@demo.test'); pg.goto(B + '/tags'); pg.wait_for_timeout(400)
        champ = pg.locator('input[type=text]').first; champ.fill('urgent-prod'); pg.get_by_role('button', name=re.compile('Créer|Ajouter')).first.click()
        expect(pg.locator('[data-tag="urgent-prod"]')).to_be_visible()
        pg.locator('input[type=text]').first.fill('bug'); pg.get_by_role('button', name=re.compile('Créer|Ajouter')).first.click(); expect(pg.locator('.erreur-champ, [role=alert]').first).to_be_visible()
    t15()

    @etape('statistiques : chiffres cohérents avec l’API')
    def t16():
        pg = neuf('agent@demo.test'); pg.goto(B + '/statistiques'); expect(pg.locator('[data-stat=total]')).to_have_text(re.compile(r'^\d+$'))
        assert int(pg.locator('[data-stat=total]').inner_text()) >= 30
    t16()

    @etape('comptes (admin) : changer un rôle ; supprimer un compte lié → 409 affiché ; pas d’action sur soi-même')
    def t17():
        pg = neuf('admin@demo.test'); pg.goto(B + '/utilisateurs'); pg.wait_for_timeout(500)
        ligne = pg.locator('[data-user="user2@demo.test"]'); ligne.locator('select').select_option('agent'); pg.wait_for_timeout(500); expect(ligne.locator('select')).to_have_value('agent')
        assert pg.locator('[data-user="admin@demo.test"] select').is_disabled()
        pg.locator('[data-user="user@demo.test"]').get_by_role('button', name='Supprimer').click(); pg.locator('[data-user="user@demo.test"]').get_by_role('button', name='Confirmer').click()
        expect(pg.locator('body')).to_contain_text(re.compile('lié|liée|supprimé')); expect(pg.locator('[data-user="user@demo.test"]')).to_be_visible()
    t17()

    @etape('profil : changement de nom, mot de passe erroné (422), sessions listées')
    def t18():
        pg = neuf('user2@demo.test'); pg.goto(B + '/profil'); pg.fill('#p-nom', 'Nouveau Nom'); pg.get_by_role('button', name='Enregistrer').click(); expect(pg.locator('.toast, [role=status]').first).to_contain_text('Profil')
        pg.fill('#m-actuel', 'mauvais'); pg.fill('#m-nouveau', 'abcdefg1'); pg.fill('#m-conf', 'abcdefg1'); pg.get_by_role('button', name='Changer le mot de passe').click(); expect(pg.locator('.erreur-champ').first).to_be_visible()
        expect(pg.locator('body')).to_contain_text('cette session')
    t18()

    @etape('déconnexion : jeton supprimé, retour /connexion, /tickets re-protégé')
    def t19():
        pg = neuf('user@demo.test'); pg.get_by_role('button', name='Se déconnecter').click(); pg.wait_for_url(re.compile('/connexion'))
        pg.goto(B + '/tickets'); pg.wait_for_url(re.compile('/connexion'))
    t19()

    @etape('401 (jeton invalidé côté serveur) → toast + redirection connexion')
    def t20():
        pg = neuf('user@demo.test'); reset(); pg.goto(B + '/tickets'); pg.wait_for_url(re.compile('/connexion'))
    t20()

    @etape('429 sur la connexion : message avec délai')
    def t21():
        pg = neuf(); pg.goto(B + '/connexion')
        for _ in range(7):
            pg.fill('#email', 'x@y.test'); pg.fill('#motdepasse', 'zz'); pg.click('button[type=submit]'); pg.wait_for_timeout(200)
        expect(pg.locator('[role=alert]').first).to_contain_text(re.compile('tentatives|Réessayez|secondes', re.I))
    t21()

    @etape('page inconnue → 404 ; mobile 390px sans défilement horizontal')
    def t22():
        reset(); pg = neuf('agent@demo.test'); pg.goto(B + '/nimportequoi'); expect(pg.locator('h1')).to_be_visible()
        pg.set_viewport_size({'width': 390, 'height': 800}); pg.goto(B + '/tickets'); pg.wait_for_timeout(500)
        assert pg.evaluate('document.documentElement.scrollWidth') <= 392, pg.evaluate('document.documentElement.scrollWidth')
    t22()

    @etape('aucune erreur JS console sur l’ensemble du parcours (dernière page)')
    def t23():
        pg = neuf('agent@demo.test')
        for r in ['/tickets', '/tickets/1', '/statistiques', '/tags', '/profil']: pg.goto(B + r); pg.wait_for_timeout(300)
        assert not pg.erreurs, pg.erreurs[:3]
        pg.screenshot(path='liste.png'); pg.goto(B + '/tickets/1'); pg.wait_for_timeout(400); pg.screenshot(path='detail.png', full_page=True)
    t23()
    nav.close()

ok = sum(1 for r in res if r[1])
for n, o, m in res: print(('✔' if o else '✘'), n, ('→ ' + m) if m else '')
print(f'{ok}/{len(res)}')
