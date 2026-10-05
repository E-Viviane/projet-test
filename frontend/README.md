# Helpdesk – interface Vue 3 pour l'API `api-rest-complete`

Vue 3 (`<script setup>`) · Vite · Pinia · Vue Router · JavaScript simple, sans framework CSS.
Écrans : connexion/inscription, liste (filtres, tri, pagination, export CSV), création, détail (rail de statut, assignation, tags, commentaires, fichiers), tags, statistiques, corbeille, comptes (admin), profil et sessions.

## Démarrage (3 commandes)
Prérequis : Node ≥ 20.19 ou ≥ 22.12 (exigé par Vite 8).
```bash
npm install
# 1) lancer l'API Laravel (dossier api-rest-complete) : php artisan serve   → http://127.0.0.1:8000
npm run dev          # → http://localhost:5173
```
Sans Laravel sous la main : `python3 mock-api/serveur.py` lance une fausse API (même contrat) sur le port 8000.
Comptes (mot de passe `password`) : admin@demo.test, agent@demo.test, user@demo.test.

## Pourquoi pas de problème CORS en développement
Vite proxifie `/api` vers `http://127.0.0.1:8000` (`vite.config.js`) : le navigateur ne parle qu'à `localhost:5173`.
Autre adresse d'API : `VITE_PROXY_TARGET=http://...` (proxy) ou `VITE_API_URL=https://.../api/v1` (appel direct → l'API doit alors autoriser l'origine dans `config/cors.php`, déjà prête pour 5173).
Production : `npm run build`, servir `dist/` et rediriger `/api` vers Laravel (ou définir `VITE_API_URL`).

## Structure
```
src/api/client.js      fetch unique : Bearer, erreurs {message, code, errors}, 401 global, AbortController, téléchargement blob
src/stores/            auth (jeton en sessionStorage), toast
src/router/index.js    routes, garde de connexion et de rôle, redirection après login
src/views/             un fichier par écran      src/components/  briques réutilisables
src/utils/             libellés + transitions de statut, formats, gestion des erreurs de formulaire
mock-api/              fausse API + e2e.py (23 scénarios Playwright)
```

## Ce qui a été testé
- `npm run build` : OK.
- **23 scénarios dans un vrai Chromium** (`python3 mock-api/e2e.py`, Vite + fausse API) : garde de routes, connexion/échecs/429, filtres synchronisés avec l'URL, pagination, création + erreurs 422 par champ, transitions + conflit 409, notes internes, upload/téléchargement/refus d'extension, export CSV, corbeille, tags, stats, comptes (409), profil, déconnexion, 401, mobile 390 px, aucune erreur console. Résultat : 23/23.
- **Non testé** : contre le vrai Laravel + MySQL (impossible ici). La fausse API suit le contrat documenté, mais un écart reste possible : si une erreur apparaît, envoyez le message exact et la réponse de l'onglet Réseau.

## Questions d'entretien probables
- **Où stocker le jeton ?** Ici `sessionStorage` : disparaît à la fermeture de l'onglet, mais lisible par du JS (XSS). `localStorage` = idem et persistant. Cookie `HttpOnly` + `SameSite` = le plus sûr contre XSS, mais demande de gérer le CSRF. Le vrai remède reste d'éviter les XSS (Vue échappe par défaut, pas de `v-html`).
- **Que fait le 401 global ?** Le client appelle `onUnauthorized` : jeton effacé, message, retour à `/connexion?redirect=…`.
- **Pourquoi l'URL porte les filtres ?** Lien partageable, bouton retour et rechargement fonctionnent ; une seule source de vérité.
- **Pourquoi `AbortController` ?** Une frappe rapide lance plusieurs requêtes : on annule la précédente pour éviter qu'une réponse lente écrase la plus récente.
- **`Idempotency-Key` ?** Générée à l'ouverture du formulaire : un double-clic ou un renvoi réseau ne crée pas deux tickets.
- **Pourquoi `fetch` + blob pour les fichiers ?** Un lien `<a href>` n'envoie pas l'en-tête `Authorization`.
- **Cacher un bouton = sécuriser ?** Non : confort d'interface seulement. La sécurité reste dans les Policies Laravel (403).
- **`ref` vs `reactive`, `computed` vs `watch`, `v-if` vs `v-show`, `key` dans `v-for`, props/emits, lazy-loading des routes** : tous utilisés dans ce projet, à relire.
