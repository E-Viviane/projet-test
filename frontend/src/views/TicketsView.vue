<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { get, telecharger } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toast'
import { texteErreur } from '../utils/erreurs'
import { debounce, formatDate, formatDateHeure } from '../utils/format'
import { LIBELLE_PRIORITE, LIBELLE_STATUT, PRIORITES, STATUTS, TRIS } from '../utils/libelles'
import StatusBadge from '../components/StatusBadge.vue'
import PriorityBadge from '../components/PriorityBadge.vue'
import PaginationBar from '../components/PaginationBar.vue'
import TagChip from '../components/TagChip.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()

// ---- L'URL est la source de vérité des filtres : on peut recharger, partager le lien, utiliser « Précédent » ----
const q = computed(() => String(route.query.q ?? ''))
const statuts = computed(() => String(route.query.statut ?? '').split(',').filter(Boolean))
const priorite = computed(() => String(route.query.priorite ?? ''))
const nonAssignes = computed(() => route.query.agent_id === 'aucun')
const enRetard = computed(() => route.query.en_retard === '1')
const tri = computed(() => String(route.query.tri ?? '-created_at'))
const page = computed(() => Number(route.query.page) || 1)
const parPage = computed(() => Number(route.query.per_page) || 15)

const recherche = ref(q.value) // valeur tapée (la requête n'est lancée qu'après une courte pause)

function modifier(changements) {
  const query = { ...route.query, ...changements }
  for (const k of Object.keys(query)) if (query[k] === '' || query[k] === null || query[k] === undefined) delete query[k]
  router.replace({ query })
}
const filtrerParTexte = debounce((valeur) => modifier({ q: valeur.trim(), page: undefined }), 350)
watch(recherche, (v) => filtrerParTexte(v))
watch(q, (v) => { if (v !== recherche.value.trim()) recherche.value = v }) // retour arrière du navigateur
onBeforeUnmount(() => filtrerParTexte.annuler())

function basculerStatut(s) {
  const liste = statuts.value.includes(s) ? statuts.value.filter((x) => x !== s) : [...statuts.value, s]
  modifier({ statut: liste.join(','), page: undefined })
}
function toutEffacer() {
  recherche.value = ''
  router.replace({ query: {} })
}
const filtresActifs = computed(() => !!(q.value || statuts.value.length || priorite.value || nonAssignes.value || enRetard.value))

// ---- Chargement : une requête à la fois, les anciennes sont annulées (équivalent de switchMap) ----
const tickets = ref([])
const meta = ref(null)
const chargement = ref(true)
const erreur = ref(null)
let controleur = null

async function charger() {
  if (route.name !== 'tickets') return // on est en train de quitter la page : inutile de recharger
  controleur?.abort()
  controleur = new AbortController()
  chargement.value = true
  erreur.value = null
  try {
    const rep = await get('/tickets', {
      signal: controleur.signal,
      query: {
        q: q.value,
        statut: statuts.value.join(','),
        priorite: priorite.value,
        agent_id: nonAssignes.value ? 'aucun' : null,
        en_retard: enRetard.value ? 1 : null,
        tri: tri.value,
        page: page.value,
        per_page: parPage.value,
        inclure: 'auteur,agent,tags',
      },
    })
    tickets.value = rep.data
    meta.value = rep.meta
  } catch (e) {
    if (e.name === 'AbortError') return
    erreur.value = texteErreur(e)
  } finally {
    if (controleur && !controleur.signal.aborted) chargement.value = false
  }
}
watch(() => route.query, charger, { immediate: true })
onBeforeUnmount(() => controleur?.abort())

// ---- Export CSV : mêmes filtres que la liste ----
const export_ = ref(false)
async function exporter() {
  export_.value = true
  try {
    await telecharger('/tickets/export', 'tickets.csv', {
      q: q.value, statut: statuts.value.join(','), priorite: priorite.value,
      agent_id: nonAssignes.value ? 'aucun' : null, en_retard: enRetard.value ? 1 : null,
    })
    toasts.succes('Export téléchargé.')
  } catch (e) {
    toasts.erreur(texteErreur(e))
  } finally {
    export_.value = false
  }
}

const sansResultat = computed(() => !chargement.value && !erreur.value && tickets.value.length === 0)
</script>

<template>
  <div class="entete">
    <div>
      <h1>Tickets</h1>
      <p>{{ auth.estAgent ? 'Toutes les demandes d\'assistance.' : 'Vos demandes d\'assistance.' }}</p>
    </div>
    <div class="ligne">
      <button class="btn" type="button" :disabled="export_" @click="exporter">{{ export_ ? 'Export…' : 'Exporter en CSV' }}</button>
      <RouterLink class="btn btn-principal" to="/tickets/nouveau">Nouveau ticket</RouterLink>
    </div>
  </div>

  <form class="filtres" role="search" aria-label="Filtrer les tickets" @submit.prevent>
    <div class="filtres-rangee">
      <div class="recherche">
        <input id="recherche" v-model="recherche" type="search" aria-label="Rechercher un ticket" placeholder="Rechercher par titre, description ou référence" />
      </div>
      <label class="coche">
        <span class="etiquette">Priorité</span>
        <select :value="priorite" style="width: auto" aria-label="Priorité" @change="modifier({ priorite: $event.target.value, page: undefined })">
          <option value="">Toutes</option>
          <option v-for="p in PRIORITES" :key="p" :value="p">{{ LIBELLE_PRIORITE[p] }}</option>
        </select>
      </label>
      <label class="coche">
        <span class="etiquette">Trier par</span>
        <select :value="tri" style="width: auto" aria-label="Trier par" @change="modifier({ tri: $event.target.value === '-created_at' ? '' : $event.target.value, page: undefined })">
          <option v-for="t in TRIS" :key="t.valeur" :value="t.valeur">{{ t.libelle }}</option>
        </select>
      </label>
    </div>

    <div class="filtres-rangee">
      <div class="puces" role="group" aria-label="Filtrer par statut">
        <button v-for="s in STATUTS" :key="s" type="button" class="puce" :aria-pressed="statuts.includes(s)" :data-statut="s" @click="basculerStatut(s)">
          {{ LIBELLE_STATUT[s] }}
        </button>
      </div>
      <label class="coche"><input type="checkbox" :checked="enRetard" @change="modifier({ en_retard: $event.target.checked ? '1' : '', page: undefined })" /> En retard</label>
      <label v-if="auth.estAgent" class="coche">
        <input type="checkbox" :checked="nonAssignes" @change="modifier({ agent_id: $event.target.checked ? 'aucun' : '', page: undefined })" /> Non assignés
      </label>
      <button v-if="filtresActifs" type="button" class="btn btn-discret btn-petit" @click="toutEffacer">Effacer les filtres</button>
    </div>
  </form>

  <div v-if="erreur" class="alerte alerte-erreur" role="alert">
    {{ erreur }} <button class="btn btn-petit" type="button" @click="charger">Réessayer</button>
  </div>

  <div v-if="sansResultat" class="vide">
    <template v-if="filtresActifs">
      <strong>Aucun ticket ne correspond à ces filtres</strong>
      <button class="btn" type="button" @click="toutEffacer">Effacer les filtres</button>
    </template>
    <template v-else>
      <strong>Aucun ticket pour le moment</strong>
      <p>Décrivez un problème pour ouvrir votre premier ticket.</p>
      <RouterLink class="btn btn-principal" to="/tickets/nouveau">Nouveau ticket</RouterLink>
    </template>
  </div>

  <div v-else-if="tickets.length || chargement" class="tableau-enveloppe" :class="{ chargement }" :aria-busy="chargement">
    <table>
      <thead>
        <tr>
          <th>Réf.</th><th>Ticket</th><th>Statut</th><th>Priorité</th>
          <th>{{ auth.estAgent ? 'Auteur / agent' : 'Agent' }}</th><th>Échéance</th><th>Créé le</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="t in tickets" :key="t.id" class="ligne-ticket" :data-priorite="t.priorite">
          <td class="reference">{{ t.reference }}</td>
          <td>
            <RouterLink class="lien-titre" :to="{ name: 'ticket', params: { id: t.id } }">{{ t.titre }}</RouterLink>
            <div v-if="t.tags?.length" class="ligne" style="gap: 0.3rem; margin-top: 0.25rem"><TagChip v-for="tag in t.tags" :key="tag.id" :tag="tag" /></div>
          </td>
          <td><StatusBadge :statut="t.statut" /></td>
          <td><PriorityBadge :priorite="t.priorite" /></td>
          <td>
            <template v-if="auth.estAgent">{{ t.auteur?.name }}<br /></template>
            <small>{{ t.agent?.name ?? 'Non assigné' }}</small>
          </td>
          <td :class="{ retard: t.en_retard }">{{ formatDate(t.echeance) }}<small v-if="t.en_retard"> · en retard</small></td>
          <td><small>{{ formatDateHeure(t.cree_le) }}</small></td>
        </tr>
      </tbody>
    </table>
  </div>

  <PaginationBar v-if="meta && !sansResultat" :meta="meta" @page="(p) => modifier({ page: p === 1 ? '' : p })" @taille="(n) => modifier({ per_page: n === 15 ? '' : n, page: undefined })" />
</template>
