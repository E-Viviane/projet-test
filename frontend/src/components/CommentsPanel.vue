<script setup>
import { onMounted, ref } from 'vue'
import { del, get, post } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toast'
import { texteErreur, useFormulaire } from '../utils/erreurs'
import { formatDateHeure } from '../utils/format'
import ConfirmButton from './ConfirmButton.vue'

const props = defineProps({ ticketId: { type: [Number, String], required: true }, ferme: Boolean })
const emit = defineEmits(['change'])

const auth = useAuthStore()
const toasts = useToastStore()
const f = useFormulaire()

const commentaires = ref([])
const chargement = ref(true)
const erreur = ref(null)
const contenu = ref('')
const interne = ref(false)

async function charger() {
  chargement.value = true
  erreur.value = null
  try {
    commentaires.value = (await get(`/tickets/${props.ticketId}/commentaires`, { query: { per_page: 100 } })).data
  } catch (e) {
    erreur.value = texteErreur(e)
  } finally {
    chargement.value = false
  }
}
onMounted(charger)

async function ajouter() {
  const rep = await f.soumettre(() => post(`/tickets/${props.ticketId}/commentaires`, { contenu: contenu.value.trim(), interne: auth.estAgent && interne.value }))
  if (!rep) return
  commentaires.value.push(rep.data)
  contenu.value = ''
  interne.value = false
  toasts.succes('Commentaire ajouté.')
  emit('change')
}

async function supprimer(c) {
  try {
    await del(`/tickets/${props.ticketId}/commentaires/${c.id}`)
    commentaires.value = commentaires.value.filter((x) => x.id !== c.id)
    toasts.succes('Commentaire supprimé.')
    emit('change')
  } catch (e) {
    toasts.erreur(texteErreur(e))
  }
}

const peutSupprimer = (c) => auth.estAdmin || c.auteur?.id === auth.user?.id
</script>

<template>
  <section class="section" aria-labelledby="titre-commentaires">
    <h2 id="titre-commentaires">Commentaires</h2>

    <p v-if="chargement" class="discret">Chargement…</p>
    <div v-else-if="erreur" class="alerte alerte-erreur" role="alert">{{ erreur }} <button class="btn btn-petit" type="button" @click="charger">Réessayer</button></div>
    <p v-else-if="!commentaires.length" class="discret">Aucun commentaire pour le moment.</p>

    <ul v-else class="liste-simple">
      <li v-for="c in commentaires" :key="c.id" class="commentaire" :class="{ interne: c.interne }" :data-commentaire="c.id">
        <header>
          <strong>{{ c.auteur?.name ?? 'Utilisateur supprimé' }}</strong>
          <small>{{ formatDateHeure(c.cree_le) }}</small>
          <span v-if="c.interne" class="pastille s-en_attente">Note interne</span>
          <ConfirmButton v-if="peutSupprimer(c)" petit libelle="Supprimer" libelle-confirmation="Confirmer" @confirme="supprimer(c)" />
        </header>
        <p>{{ c.contenu }}</p>
      </li>
    </ul>

    <form v-if="!ferme" novalidate style="margin-top: 1rem; max-width: 640px" @submit.prevent="ajouter">
      <div v-if="f.message.value" class="alerte alerte-erreur" role="alert">{{ f.message.value }}</div>
      <div class="champ" :class="{ invalide: f.champ('contenu') }">
        <label for="nouveau-commentaire">Ajouter un commentaire</label>
        <textarea id="nouveau-commentaire" v-model="contenu" style="min-height: 5rem" />
        <p v-if="f.champ('contenu')" class="erreur-champ">{{ f.champ('contenu') }}</p>
      </div>
      <div class="ligne">
        <button class="btn btn-principal" type="submit" :disabled="f.enCours.value || !contenu.trim()">Publier</button>
        <label v-if="auth.estAgent" class="coche"><input v-model="interne" type="checkbox" /> Note interne (invisible pour l'auteur du ticket)</label>
      </div>
    </form>
    <p v-else class="aide">Ce ticket est fermé : il n'accepte plus de commentaires.</p>
  </section>
</template>
