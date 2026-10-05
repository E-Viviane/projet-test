<script setup>
import { ref } from 'vue'
import { del, post, telecharger } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toast'
import { texteErreur, useFormulaire } from '../utils/erreurs'
import { formatTaille } from '../utils/format'
import ConfirmButton from './ConfirmButton.vue'

const props = defineProps({ ticketId: { type: [Number, String], required: true }, pieces: { type: Array, default: () => [] } })
const emit = defineEmits(['change'])

const auth = useAuthStore()
const toasts = useToastStore()
const f = useFormulaire()
const champFichier = ref(null)

const EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'txt', 'docx', 'xlsx']
const TAILLE_MAX = 5 * 1024 * 1024
const erreurLocale = ref(null)

// Les mêmes limites que l'API : le contrôle côté navigateur évite un envoi inutile, mais seul le serveur fait foi.
async function envoyer(event) {
  const fichier = event.target.files?.[0]
  erreurLocale.value = null
  if (!fichier) return
  const ext = fichier.name.split('.').pop().toLowerCase()
  if (!EXTENSIONS.includes(ext)) {
    erreurLocale.value = `Format « .${ext} » non accepté. Formats autorisés : ${EXTENSIONS.join(', ')}.`
  } else if (fichier.size > TAILLE_MAX) {
    erreurLocale.value = `Fichier trop volumineux (${formatTaille(fichier.size)}). Maximum : 5 Mo.`
  } else {
    const form = new FormData()
    form.append('fichier', fichier)
    const rep = await f.soumettre(() => post(`/tickets/${props.ticketId}/pieces-jointes`, undefined, { form }))
    if (rep) {
      toasts.succes(`« ${rep.data.nom} » ajouté.`)
      emit('change')
    }
  }
  if (champFichier.value) champFichier.value.value = '' // permet de renvoyer le même fichier
}

async function ouvrir(p) {
  try {
    await telecharger(`/pieces-jointes/${p.id}`, p.nom)
  } catch (e) {
    toasts.erreur(texteErreur(e))
  }
}

async function supprimer(p) {
  try {
    await del(`/pieces-jointes/${p.id}`)
    toasts.succes('Fichier supprimé.')
    emit('change')
  } catch (e) {
    toasts.erreur(texteErreur(e))
  }
}

const peutSupprimer = (p) => auth.estAdmin || p.depose_par === auth.user?.id
</script>

<template>
  <section class="section" aria-labelledby="titre-pj">
    <h2 id="titre-pj">Fichiers joints</h2>

    <p v-if="!pieces.length" class="discret">Aucun fichier joint.</p>
    <ul v-else class="liste-simple">
      <li v-for="p in pieces" :key="p.id" class="ligne" style="justify-content: space-between">
        <span>
          <button type="button" class="btn btn-discret btn-petit" style="color: var(--marque); text-decoration: underline; padding-left: 0" @click="ouvrir(p)">{{ p.nom }}</button>
          <small>{{ formatTaille(p.taille) }}</small>
        </span>
        <ConfirmButton v-if="peutSupprimer(p)" petit libelle="Supprimer" libelle-confirmation="Confirmer" @confirme="supprimer(p)" />
      </li>
    </ul>

    <div v-if="erreurLocale" class="alerte alerte-erreur" role="alert">{{ erreurLocale }}</div>
    <div v-if="f.message.value" class="alerte alerte-erreur" role="alert">{{ f.message.value }}</div>
    <div v-if="f.champ('fichier')" class="alerte alerte-erreur" role="alert">{{ f.champ('fichier') }}</div>

    <div class="champ" style="margin-top: 0.75rem">
      <label for="fichier">Ajouter un fichier</label>
      <input id="fichier" ref="champFichier" type="file" :disabled="f.enCours.value" accept=".pdf,.png,.jpg,.jpeg,.txt,.docx,.xlsx" @change="envoyer" />
      <p class="aide">PDF, image, texte, Word ou Excel — 5 Mo maximum, 10 fichiers par ticket.</p>
    </div>
  </section>
</template>
