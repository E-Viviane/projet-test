<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { del, get, patch } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toast'
import { texteErreur } from '../utils/erreurs'
import { debounce } from '../utils/format'
import { LIBELLE_ROLE } from '../utils/libelles'
import ConfirmButton from '../components/ConfirmButton.vue'
import PaginationBar from '../components/PaginationBar.vue'

const auth = useAuthStore()
const toasts = useToastStore()

const utilisateurs = ref([])
const meta = ref(null)
const chargement = ref(true)
const erreur = ref(null)

const recherche = ref('')
const role = ref('')
const page = ref(1)
const parPage = ref(15)
let controleur = null

async function charger() {
  controleur?.abort()
  controleur = new AbortController()
  chargement.value = true
  erreur.value = null
  try {
    const rep = await get('/utilisateurs', { signal: controleur.signal, query: { q: recherche.value.trim(), role: role.value, page: page.value, per_page: parPage.value } })
    utilisateurs.value = rep.data
    meta.value = rep.meta
  } catch (e) {
    if (e.name === 'AbortError') return
    erreur.value = texteErreur(e)
  } finally {
    if (controleur && !controleur.signal.aborted) chargement.value = false
  }
}
onMounted(charger)
onBeforeUnmount(() => controleur?.abort())

const rechercher = debounce(() => { page.value = 1; charger() }, 350)
watch(recherche, rechercher)
onBeforeUnmount(() => rechercher.annuler())
watch(role, () => { page.value = 1; charger() })

const enAction = ref(null) // id du compte en cours de modification

async function changerRole(u, nouveau) {
  if (nouveau === u.role) return
  enAction.value = u.id
  try {
    const rep = await patch(`/utilisateurs/${u.id}`, { role: nouveau })
    u.role = rep.data.role
    toasts.succes(`${u.name} est maintenant ${LIBELLE_ROLE[u.role].toLowerCase()}.`)
  } catch (e) {
    toasts.erreur(texteErreur(e)) // ex. 409 : on ne change pas son propre rôle
    await charger()               // remet le sélecteur dans l'état réel
  } finally {
    enAction.value = null
  }
}

async function supprimer(u) {
  enAction.value = u.id
  try {
    await del(`/utilisateurs/${u.id}`)
    toasts.succes(`Compte de ${u.name} supprimé.`)
    await charger()
  } catch (e) {
    toasts.erreur(texteErreur(e)) // 409 : le compte a des tickets, commentaires ou fichiers
  } finally {
    enAction.value = null
  }
}

const sansResultat = computed(() => !chargement.value && !erreur.value && utilisateurs.value.length === 0)
</script>

<template>
  <div class="entete">
    <div>
      <h1>Comptes</h1>
      <p>Gérez les rôles. Un compte qui a des tickets ou des commentaires ne peut pas être supprimé.</p>
    </div>
  </div>

  <div class="filtres-rangee" style="margin-bottom: 1rem">
    <div class="recherche"><input v-model="recherche" type="search" aria-label="Rechercher un compte" placeholder="Rechercher par nom ou e-mail" /></div>
    <label class="coche"><span class="etiquette">Rôle</span>
      <select v-model="role" style="width: auto" aria-label="Filtrer par rôle">
        <option value="">Tous</option>
        <option v-for="(libelle, valeur) in LIBELLE_ROLE" :key="valeur" :value="valeur">{{ libelle }}</option>
      </select>
    </label>
  </div>

  <div v-if="erreur" class="alerte alerte-erreur" role="alert">{{ erreur }} <button class="btn btn-petit" type="button" @click="charger">Réessayer</button></div>
  <div v-if="sansResultat" class="vide"><strong>Aucun compte ne correspond</strong></div>

  <div v-else-if="utilisateurs.length" class="tableau-enveloppe" :class="{ chargement }">
    <table>
      <thead><tr><th>Nom</th><th>E-mail</th><th>Rôle</th><th class="numerique">Tickets</th><th><span class="sr-seul">Actions</span></th></tr></thead>
      <tbody>
        <tr v-for="u in utilisateurs" :key="u.id" :data-user="u.email">
          <td><strong>{{ u.name }}</strong><small v-if="u.id === auth.user?.id"> (vous)</small></td>
          <td>{{ u.email }}</td>
          <td>
            <select :value="u.role" style="width: auto" :disabled="enAction === u.id || u.id === auth.user?.id" :aria-label="'Rôle de ' + u.name" @change="changerRole(u, $event.target.value)">
              <option v-for="(libelle, valeur) in LIBELLE_ROLE" :key="valeur" :value="valeur">{{ libelle }}</option>
            </select>
          </td>
          <td class="numerique">{{ u.tickets_count }}</td>
          <td><ConfirmButton v-if="u.id !== auth.user?.id" petit libelle="Supprimer" libelle-confirmation="Confirmer" :desactive="enAction === u.id" @confirme="supprimer(u)" /></td>
        </tr>
      </tbody>
    </table>
  </div>

  <PaginationBar v-if="meta && !sansResultat" :meta="meta" @page="(p) => { page = p; charger() }" @taille="(n) => { parPage = n; page = 1; charger() }" />
</template>
