<script setup>
import { onMounted, ref } from 'vue'
import { del, get, post } from '../api/client'
import { useToastStore } from '../stores/toast'
import { texteErreur } from '../utils/erreurs'
import { formatDateHeure } from '../utils/format'
import ConfirmButton from '../components/ConfirmButton.vue'
import PaginationBar from '../components/PaginationBar.vue'

const toasts = useToastStore()
const tickets = ref([])
const meta = ref(null)
const page = ref(1)
const parPage = ref(15)
const chargement = ref(true)
const erreur = ref(null)

async function charger() {
  chargement.value = true
  erreur.value = null
  try {
    const rep = await get('/tickets/corbeille', { query: { page: page.value, per_page: parPage.value } })
    // Si la dernière page vient de se vider, on revient à la précédente
    if (!rep.data.length && rep.meta.current_page > 1) { page.value = rep.meta.last_page; return charger() }
    tickets.value = rep.data
    meta.value = rep.meta
  } catch (e) {
    erreur.value = texteErreur(e)
  } finally {
    chargement.value = false
  }
}
onMounted(charger)

async function restaurer(t) {
  try {
    await post(`/tickets/${t.id}/restauration`)
    toasts.succes(`Ticket ${t.reference} restauré.`)
    charger()
  } catch (e) {
    toasts.erreur(texteErreur(e))
    charger()
  }
}
async function supprimerDefinitivement(t) {
  try {
    await del(`/tickets/${t.id}/definitif`)
    toasts.succes(`Ticket ${t.reference} supprimé définitivement.`)
    charger()
  } catch (e) {
    toasts.erreur(texteErreur(e))
  }
}
</script>

<template>
  <div class="entete">
    <div>
      <h1>Corbeille</h1>
      <p>Tickets supprimés. Restaurez-les, ou supprimez-les définitivement (irréversible, fichiers compris).</p>
    </div>
  </div>

  <p v-if="chargement && !tickets.length" class="discret">Chargement…</p>
  <div v-else-if="erreur" class="alerte alerte-erreur" role="alert">{{ erreur }} <button class="btn btn-petit" type="button" @click="charger">Réessayer</button></div>
  <div v-else-if="!tickets.length" class="vide"><strong>La corbeille est vide</strong><p>Les tickets supprimés apparaîtront ici.</p></div>

  <template v-else>
    <div class="tableau-enveloppe">
      <table>
        <thead><tr><th>Réf.</th><th>Ticket</th><th>Supprimé le</th><th><span class="sr-seul">Actions</span></th></tr></thead>
        <tbody>
          <tr v-for="t in tickets" :key="t.id" :data-ticket="t.id">
            <td class="reference">{{ t.reference }}</td>
            <td><strong>{{ t.titre }}</strong></td>
            <td><small>{{ formatDateHeure(t.supprime_le) }}</small></td>
            <td><div class="ligne">
              <button class="btn btn-petit" type="button" data-action="restaurer" @click="restaurer(t)">Restaurer</button>
              <ConfirmButton petit libelle="Supprimer définitivement" libelle-confirmation="Confirmer : irréversible" @confirme="supprimerDefinitivement(t)" />
            </div></td>
          </tr>
        </tbody>
      </table>
    </div>
    <PaginationBar v-if="meta" :meta="meta" @page="(p) => { page = p; charger() }" @taille="(n) => { parPage = n; page = 1; charger() }" />
  </template>
</template>
