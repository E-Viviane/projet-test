<script setup>
import { onMounted, reactive, ref } from 'vue'
import { del, get, patch, post } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toast'
import { texteErreur, useFormulaire } from '../utils/erreurs'
import ConfirmButton from '../components/ConfirmButton.vue'

const auth = useAuthStore()
const toasts = useToastStore()

const tags = ref([])
const chargement = ref(true)
const erreur = ref(null)

async function charger() {
  chargement.value = true
  erreur.value = null
  try {
    tags.value = (await get('/tags')).data
  } catch (e) {
    erreur.value = texteErreur(e)
  } finally {
    chargement.value = false
  }
}
onMounted(charger)

// ---- création ----
const fCreation = useFormulaire()
const nouveau = reactive({ nom: '', couleur: '#2563eb' })
async function creer() {
  const rep = await fCreation.soumettre(() => post('/tags', { nom: nouveau.nom.trim(), couleur: nouveau.couleur }))
  if (!rep) return
  tags.value = [...tags.value, rep.data].sort((a, b) => a.nom.localeCompare(b.nom, 'fr'))
  nouveau.nom = ''
  toasts.succes(`Tag « ${rep.data.nom} » créé.`)
}

// ---- modification en ligne ----
const enEdition = ref(null)
const brouillon = reactive({ nom: '', couleur: '#888888' })
const fEdition = useFormulaire()
function editer(t) {
  enEdition.value = t.id
  Object.assign(brouillon, { nom: t.nom, couleur: t.couleur })
  fEdition.reinitialiser()
}
async function enregistrer(t) {
  const rep = await fEdition.soumettre(() => patch(`/tags/${t.id}`, { nom: brouillon.nom.trim(), couleur: brouillon.couleur }))
  if (!rep) return
  Object.assign(t, rep.data)
  enEdition.value = null
  toasts.succes('Tag modifié.')
}

async function supprimer(t) {
  try {
    await del(`/tags/${t.id}`)
    tags.value = tags.value.filter((x) => x.id !== t.id)
    toasts.succes(`Tag « ${t.nom} » supprimé.`)
  } catch (e) {
    toasts.erreur(texteErreur(e))
  }
}
</script>

<template>
  <div class="entete">
    <div>
      <h1>Tags</h1>
      <p>Étiquettes pour classer les tickets (réseau, matériel, accès…).</p>
    </div>
  </div>

  <form class="ligne" style="align-items: flex-start; margin-bottom: 1.5rem" novalidate @submit.prevent="creer">
    <div class="champ" :class="{ invalide: fCreation.champ('nom') }" style="margin: 0; min-width: 240px">
      <label for="nom-tag">Nouveau tag</label>
      <input id="nom-tag" v-model="nouveau.nom" type="text" maxlength="50" />
      <p v-if="fCreation.champ('nom')" class="erreur-champ">{{ fCreation.champ('nom') }}</p>
    </div>
    <div class="champ" style="margin: 0">
      <label for="couleur-tag">Couleur</label>
      <input id="couleur-tag" v-model="nouveau.couleur" type="color" style="width: 3.5rem; height: 2.4rem; padding: 0.15rem" />
      <p v-if="fCreation.champ('couleur')" class="erreur-champ">{{ fCreation.champ('couleur') }}</p>
    </div>
    <button class="btn btn-principal" type="submit" style="margin-top: 1.55rem" :disabled="fCreation.enCours.value || !nouveau.nom.trim()">Créer le tag</button>
  </form>
  <div v-if="fCreation.message.value" class="alerte alerte-erreur" role="alert">{{ fCreation.message.value }}</div>

  <p v-if="chargement" class="discret">Chargement…</p>
  <div v-else-if="erreur" class="alerte alerte-erreur" role="alert">{{ erreur }} <button class="btn btn-petit" type="button" @click="charger">Réessayer</button></div>
  <div v-else-if="!tags.length" class="vide"><strong>Aucun tag</strong><p>Créez le premier tag avec le formulaire ci-dessus.</p></div>

  <div v-else class="tableau-enveloppe">
    <table>
      <thead><tr><th>Tag</th><th class="numerique">Tickets</th><th><span class="sr-seul">Actions</span></th></tr></thead>
      <tbody>
        <tr v-for="t in tags" :key="t.id" :data-tag="t.nom">
          <template v-if="enEdition === t.id">
            <td>
              <div class="ligne">
                <input v-model="brouillon.nom" type="text" maxlength="50" :aria-label="'Nom du tag ' + t.nom" style="max-width: 220px" />
                <input v-model="brouillon.couleur" type="color" aria-label="Couleur" style="width: 3rem; height: 2.2rem; padding: 0.1rem" />
              </div>
              <p v-if="fEdition.champ('nom')" class="erreur-champ">{{ fEdition.champ('nom') }}</p>
              <p v-if="fEdition.message.value" class="erreur-champ">{{ fEdition.message.value }}</p>
            </td>
            <td class="numerique">{{ t.tickets_count }}</td>
            <td><div class="ligne">
              <button class="btn btn-petit btn-principal" type="button" :disabled="fEdition.enCours.value" @click="enregistrer(t)">Enregistrer</button>
              <button class="btn btn-petit" type="button" @click="enEdition = null">Annuler</button>
            </div></td>
          </template>
          <template v-else>
            <td><span class="etiquette-tag" :style="{ '--tag': t.couleur }">{{ t.nom }}</span></td>
            <td class="numerique">{{ t.tickets_count }}</td>
            <td><div class="ligne">
              <button class="btn btn-petit" type="button" @click="editer(t)">Modifier</button>
              <ConfirmButton v-if="auth.estAdmin" petit libelle="Supprimer" libelle-confirmation="Confirmer" @confirme="supprimer(t)" />
            </div></td>
          </template>
        </tr>
      </tbody>
    </table>
  </div>
</template>
