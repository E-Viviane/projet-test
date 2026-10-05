<script setup>
import { reactive } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toast'
import { useFormulaire } from '../utils/erreurs'

const auth = useAuthStore()
const router = useRouter()
const toasts = useToastStore()
const f = useFormulaire()

const form = reactive({ name: '', email: '', password: '', password_confirmation: '' })

async function envoyer() {
  await f.soumettre(() => auth.inscription({ ...form, email: form.email.trim() }))
  if (auth.estConnecte) {
    toasts.succes('Compte créé. Bienvenue !')
    router.push('/tickets')
  }
}
</script>

<template>
  <div class="carte-connexion">
    <div class="marque"><span>Helpdesk</span></div>
    <h1>Créer un compte</h1>
    <p class="discret">Un compte permet de déposer des tickets et de suivre leur traitement.</p>

    <div v-if="f.message.value" class="alerte alerte-erreur" role="alert">{{ f.message.value }}</div>

    <form novalidate @submit.prevent="envoyer">
      <div class="champ" :class="{ invalide: f.champ('name') }">
        <label for="nom">Nom complet</label>
        <input id="nom" v-model="form.name" type="text" autocomplete="name" required />
        <p v-if="f.champ('name')" class="erreur-champ">{{ f.champ('name') }}</p>
      </div>
      <div class="champ" :class="{ invalide: f.champ('email') }">
        <label for="email">Adresse e-mail</label>
        <input id="email" v-model="form.email" type="email" autocomplete="email" required />
        <p v-if="f.champ('email')" class="erreur-champ">{{ f.champ('email') }}</p>
      </div>
      <div class="champ" :class="{ invalide: f.champ('password') }">
        <label for="mdp">Mot de passe</label>
        <input id="mdp" v-model="form.password" type="password" autocomplete="new-password" required aria-describedby="aide-mdp" />
        <p id="aide-mdp" class="aide">8 caractères minimum, avec au moins une lettre et un chiffre.</p>
        <p v-if="f.champ('password')" class="erreur-champ">{{ f.champ('password') }}</p>
      </div>
      <div class="champ">
        <label for="mdp2">Confirmer le mot de passe</label>
        <input id="mdp2" v-model="form.password_confirmation" type="password" autocomplete="new-password" required />
      </div>
      <button class="btn btn-principal" type="submit" :disabled="f.enCours.value">
        {{ f.enCours.value ? 'Création…' : 'Créer mon compte' }}
      </button>
    </form>

    <p style="margin-top: 1rem">Déjà un compte ? <RouterLink to="/connexion">Se connecter</RouterLink></p>
  </div>
</template>
