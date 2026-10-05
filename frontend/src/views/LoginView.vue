<script setup>
import { ref } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useFormulaire } from '../utils/erreurs'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const f = useFormulaire()

const email = ref('')
const motDePasse = ref('')

const COMPTES_DEMO = [
  { libelle: 'Utilisateur', email: 'user@demo.test' },
  { libelle: 'Agent', email: 'agent@demo.test' },
  { libelle: 'Administrateur', email: 'admin@demo.test' },
]

function remplir(compte) {
  email.value = compte.email
  motDePasse.value = 'password'
}

async function envoyer() {
  const ok = await f.soumettre(() => auth.connexion(email.value.trim(), motDePasse.value))
  if (ok === undefined && !auth.estConnecte) return
  // Redirection vers la page demandée avant la connexion (ex. un lien de ticket), seulement si elle est interne
  const cible = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : '/tickets'
  router.push(cible)
}
</script>

<template>
  <div class="carte-connexion">
    <div class="marque">
      <svg viewBox="0 0 32 32" width="32" height="32" aria-hidden="true">
        <rect width="32" height="32" rx="7" fill="currentColor" />
        <path d="M9 17l5 5 9-11" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
      <span>Helpdesk</span>
    </div>

    <h1>Connexion</h1>
    <p class="discret">Connectez-vous pour suivre vos demandes d'assistance.</p>

    <div v-if="route.query.redirect" class="alerte alerte-info">Connectez-vous pour accéder à cette page.</div>
    <div v-if="f.message.value" class="alerte alerte-erreur" role="alert">{{ f.message.value }}</div>

    <form novalidate @submit.prevent="envoyer">
      <div class="champ" :class="{ invalide: f.champ('email') }">
        <label for="email">Adresse e-mail</label>
        <input id="email" v-model="email" type="email" autocomplete="username" required />
        <p v-if="f.champ('email')" class="erreur-champ">{{ f.champ('email') }}</p>
      </div>
      <div class="champ" :class="{ invalide: f.champ('password') }">
        <label for="motdepasse">Mot de passe</label>
        <input id="motdepasse" v-model="motDePasse" type="password" autocomplete="current-password" required />
        <p v-if="f.champ('password')" class="erreur-champ">{{ f.champ('password') }}</p>
      </div>
      <button class="btn btn-principal" type="submit" :disabled="f.enCours.value">
        {{ f.enCours.value ? 'Connexion…' : 'Se connecter' }}
      </button>
    </form>

    <p style="margin-top: 1rem">Pas encore de compte ? <RouterLink to="/inscription">Créer un compte</RouterLink></p>

    <div class="comptes-demo">
      <small>Comptes de démonstration (mot de passe : password)</small>
      <div class="ligne">
        <button v-for="c in COMPTES_DEMO" :key="c.email" type="button" class="btn btn-petit" :data-demo="c.email" @click="remplir(c)">{{ c.libelle }}</button>
      </div>
    </div>
  </div>
</template>
