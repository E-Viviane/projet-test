<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { del, get, patch, post, put } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toast'
import { texteErreur, useFormulaire } from '../utils/erreurs'
import { formatDateHeure } from '../utils/format'
import { LIBELLE_ROLE } from '../utils/libelles'

const auth = useAuthStore()
const toasts = useToastStore()
const router = useRouter()

// ---- Profil ----
const fProfil = useFormulaire()
const profil = reactive({ name: auth.user?.name ?? '', email: auth.user?.email ?? '' })
async function enregistrerProfil() {
  const rep = await fProfil.soumettre(() => patch('/auth/moi', { name: profil.name.trim(), email: profil.email.trim() }))
  if (!rep) return
  auth.user = rep.data
  toasts.succes('Profil mis à jour.')
}

// ---- Mot de passe ----
const fMdp = useFormulaire()
const mdp = reactive({ mot_de_passe_actuel: '', password: '', password_confirmation: '' })
async function changerMdp() {
  const ok = await fMdp.soumettre(async () => { await put('/auth/mot-de-passe', { ...mdp }); return true })
  if (!ok) return
  Object.assign(mdp, { mot_de_passe_actuel: '', password: '', password_confirmation: '' })
  toasts.succes('Mot de passe modifié. Vos autres appareils ont été déconnectés.')
  chargerJetons()
}

// ---- Sessions (jetons) ----
const jetons = ref([])
const erreurJetons = ref(null)
async function chargerJetons() {
  try {
    jetons.value = (await get('/auth/jetons')).data
    erreurJetons.value = null
  } catch (e) {
    erreurJetons.value = texteErreur(e)
  }
}
onMounted(chargerJetons)

async function revoquer(j) {
  try {
    await del(`/auth/jetons/${j.id}`)
    toasts.succes('Session fermée.')
    chargerJetons()
  } catch (e) {
    toasts.erreur(texteErreur(e))
  }
}

async function toutDeconnecter() {
  try {
    await post('/auth/deconnexion-totale')
    auth.effacer()
    toasts.succes('Toutes les sessions sont fermées.')
    router.push({ name: 'connexion' })
  } catch (e) {
    toasts.erreur(texteErreur(e))
  }
}
</script>

<template>
  <div class="entete">
    <div>
      <h1>Mon compte</h1>
      <p>{{ LIBELLE_ROLE[auth.user?.role] }} · {{ auth.user?.email }}</p>
    </div>
  </div>

  <div style="max-width: 560px">
    <section class="section section-pale" aria-labelledby="t-profil">
      <h2 id="t-profil">Informations personnelles</h2>
      <div v-if="fProfil.message.value" class="alerte alerte-erreur" role="alert">{{ fProfil.message.value }}</div>
      <form novalidate @submit.prevent="enregistrerProfil">
        <div class="champ" :class="{ invalide: fProfil.champ('name') }">
          <label for="p-nom">Nom</label>
          <input id="p-nom" v-model="profil.name" type="text" autocomplete="name" />
          <p v-if="fProfil.champ('name')" class="erreur-champ">{{ fProfil.champ('name') }}</p>
        </div>
        <div class="champ" :class="{ invalide: fProfil.champ('email') }">
          <label for="p-email">Adresse e-mail</label>
          <input id="p-email" v-model="profil.email" type="email" autocomplete="email" />
          <p v-if="fProfil.champ('email')" class="erreur-champ">{{ fProfil.champ('email') }}</p>
        </div>
        <button class="btn btn-principal" type="submit" :disabled="fProfil.enCours.value">Enregistrer</button>
      </form>
    </section>

    <section class="section section-pale" aria-labelledby="t-mdp">
      <h2 id="t-mdp">Mot de passe</h2>
      <div v-if="fMdp.message.value" class="alerte alerte-erreur" role="alert">{{ fMdp.message.value }}</div>
      <form novalidate @submit.prevent="changerMdp">
        <div class="champ" :class="{ invalide: fMdp.champ('mot_de_passe_actuel') }">
          <label for="m-actuel">Mot de passe actuel</label>
          <input id="m-actuel" v-model="mdp.mot_de_passe_actuel" type="password" autocomplete="current-password" />
          <p v-if="fMdp.champ('mot_de_passe_actuel')" class="erreur-champ">{{ fMdp.champ('mot_de_passe_actuel') }}</p>
        </div>
        <div class="champ" :class="{ invalide: fMdp.champ('password') }">
          <label for="m-nouveau">Nouveau mot de passe</label>
          <input id="m-nouveau" v-model="mdp.password" type="password" autocomplete="new-password" />
          <p class="aide">8 caractères minimum, avec au moins une lettre et un chiffre.</p>
          <p v-if="fMdp.champ('password')" class="erreur-champ">{{ fMdp.champ('password') }}</p>
        </div>
        <div class="champ">
          <label for="m-conf">Confirmer le nouveau mot de passe</label>
          <input id="m-conf" v-model="mdp.password_confirmation" type="password" autocomplete="new-password" />
        </div>
        <button class="btn btn-principal" type="submit" :disabled="fMdp.enCours.value">Changer le mot de passe</button>
      </form>
    </section>

    <section class="section section-pale" aria-labelledby="t-sessions">
      <h2 id="t-sessions">Sessions ouvertes</h2>
      <p class="aide">Chaque appareil connecté a une session. Fermez celles que vous ne reconnaissez pas.</p>
      <div v-if="erreurJetons" class="alerte alerte-erreur" role="alert">{{ erreurJetons }}</div>
      <ul class="liste-simple">
        <li v-for="j in jetons" :key="j.id" class="ligne" style="justify-content: space-between">
          <span>
            <strong>{{ j.nom }}</strong><small v-if="j.courant"> (cette session)</small><br />
            <small>Dernière activité : {{ formatDateHeure(j.derniere_utilisation) }} · expire le {{ formatDateHeure(j.expire_le) }}</small>
          </span>
          <button v-if="!j.courant" class="btn btn-petit" type="button" @click="revoquer(j)">Fermer</button>
        </li>
      </ul>
      <button class="btn btn-danger" type="button" style="margin-top: 0.75rem" @click="toutDeconnecter">Se déconnecter partout</button>
    </section>
  </div>
</template>
