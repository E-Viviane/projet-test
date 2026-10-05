<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { ApiError, del, get, patch, post, put } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toast'
import { texteErreur, useFormulaire } from '../utils/erreurs'
import { aujourdhui, formatDate, formatDateHeure } from '../utils/format'
import { LIBELLE_PRIORITE, LIBELLE_STATUT, PRIORITES } from '../utils/libelles'
import StatusRail from '../components/StatusRail.vue'
import PriorityBadge from '../components/PriorityBadge.vue'
import TagChip from '../components/TagChip.vue'
import ConfirmButton from '../components/ConfirmButton.vue'
import CommentsPanel from '../components/CommentsPanel.vue'
import AttachmentsPanel from '../components/AttachmentsPanel.vue'

const props = defineProps({ id: { type: String, required: true } })

const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()

const ticket = ref(null)
const chargement = ref(true)
const erreurChargement = ref(null) // { status, texte }
const agents = ref([])
const tagsDisponibles = ref([])

async function charger() {
  chargement.value = true
  erreurChargement.value = null
  try {
    ticket.value = (await get(`/tickets/${props.id}`, { query: { inclure: 'auteur,agent,tags,pieces_jointes' } })).data
    if (auth.estAgent && !agents.value.length) agents.value = (await get('/agents')).data
    if (!tagsDisponibles.value.length) tagsDisponibles.value = (await get('/tags')).data
  } catch (e) {
    ticket.value = null
    erreurChargement.value = { status: e.status ?? 0, texte: texteErreur(e) }
  } finally {
    chargement.value = false
  }
}
onMounted(charger)
watch(() => props.id, charger)

/** Recharge le ticket sans écran de chargement (après une action). */
async function rafraichir() {
  try {
    ticket.value = (await get(`/tickets/${props.id}`, { query: { inclure: 'auteur,agent,tags,pieces_jointes' } })).data
  } catch (e) {
    toasts.erreur(texteErreur(e))
  }
}

const ferme = computed(() => ticket.value?.statut === 'ferme')
const peutModifier = computed(() => ticket.value?.permissions?.modifier && !ferme.value)

// ------------------------------------------------------------ transitions de statut
const transitionEnCours = ref(false)
const alerteTransition = ref(null)

async function changerStatut(vers) {
  transitionEnCours.value = true
  alerteTransition.value = null
  try {
    ticket.value = { ...ticket.value, ...(await post(`/tickets/${props.id}/transitions`, { statut: vers })).data }
    toasts.succes(`Statut : ${LIBELLE_STATUT[vers]}.`)
  } catch (e) {
    if (e instanceof ApiError && e.status === 409) {
      // L'état a changé entre-temps (un collègue a agi) : on le dit, puis on recharge la réalité
      const permises = (e.body.transitions_permises || []).map((s) => LIBELLE_STATUT[s]).join(', ')
      alerteTransition.value = `${e.message}${permises ? ` Étapes possibles : ${permises}.` : ''}`
      await rafraichir()
    } else {
      toasts.erreur(texteErreur(e))
    }
  } finally {
    transitionEnCours.value = false
  }
}

// ------------------------------------------------------------ assignation
const assignation = reactive({ valeur: '', enCours: false })
watch(ticket, (t) => { assignation.valeur = t?.agent?.id ? String(t.agent.id) : '' }, { immediate: true })

async function assigner() {
  if (!assignation.valeur) return
  assignation.enCours = true
  try {
    ticket.value = { ...ticket.value, ...(await post(`/tickets/${props.id}/assignation`, { agent_id: Number(assignation.valeur) })).data }
    toasts.succes('Ticket assigné.')
  } catch (e) {
    toasts.erreur(texteErreur(e))
  } finally {
    assignation.enCours = false
  }
}
async function retirerAssignation() {
  assignation.enCours = true
  try {
    await del(`/tickets/${props.id}/assignation`)
    await rafraichir()
    toasts.succes('Assignation retirée.')
  } catch (e) {
    toasts.erreur(texteErreur(e))
  } finally {
    assignation.enCours = false
  }
}

// ------------------------------------------------------------ modification (PATCH : seuls les champs changés sont envoyés)
const edition = ref(false)
const fEdition = useFormulaire()
const brouillon = reactive({ titre: '', description: '', priorite: 'normale', echeance: '' })

function ouvrirEdition() {
  Object.assign(brouillon, {
    titre: ticket.value.titre, description: ticket.value.description,
    priorite: ticket.value.priorite, echeance: ticket.value.echeance ?? '',
  })
  fEdition.reinitialiser()
  edition.value = true
}

async function enregistrer() {
  const t = ticket.value
  const corps = {}
  if (brouillon.titre.trim() !== t.titre) corps.titre = brouillon.titre.trim()
  if (brouillon.description.trim() !== t.description) corps.description = brouillon.description.trim()
  if (brouillon.priorite !== t.priorite) corps.priorite = brouillon.priorite
  if ((brouillon.echeance || null) !== (t.echeance || null)) corps.echeance = brouillon.echeance || null

  if (!Object.keys(corps).length) { edition.value = false; return }

  const rep = await fEdition.soumettre(() => patch(`/tickets/${props.id}`, corps))
  if (!rep) return
  ticket.value = { ...ticket.value, ...rep.data }
  edition.value = false
  toasts.succes('Ticket modifié.')
}

// ------------------------------------------------------------ tags
const tagsChoisis = ref([])
const fTags = useFormulaire()
watch(ticket, (t) => { tagsChoisis.value = (t?.tags ?? []).map((x) => x.id) }, { immediate: true })
const tagsModifies = computed(() => {
  const actuels = (ticket.value?.tags ?? []).map((x) => x.id).sort().join(',')
  return actuels !== [...tagsChoisis.value].sort().join(',')
})

async function enregistrerTags() {
  const rep = await fTags.soumettre(() => put(`/tickets/${props.id}/tags`, { tags: tagsChoisis.value }))
  if (!rep) return
  ticket.value = { ...ticket.value, tags: rep.data.tags }
  toasts.succes('Tags enregistrés.')
}

// ------------------------------------------------------------ suppression
async function supprimer() {
  try {
    await del(`/tickets/${props.id}`)
    toasts.succes('Ticket supprimé.')
    router.push('/tickets')
  } catch (e) {
    toasts.erreur(texteErreur(e))
  }
}

const min = aujourdhui()
</script>

<template>
  <p v-if="chargement" class="discret" aria-live="polite">Chargement du ticket…</p>

  <div v-else-if="erreurChargement" class="vide">
    <strong v-if="erreurChargement.status === 404">Ticket introuvable</strong>
    <strong v-else-if="erreurChargement.status === 403">Accès refusé</strong>
    <strong v-else>Impossible d'afficher ce ticket</strong>
    <p>{{ erreurChargement.status === 403 ? 'Ce ticket appartient à un autre utilisateur.' : erreurChargement.texte }}</p>
    <div class="ligne">
      <RouterLink class="btn" to="/tickets">Retour aux tickets</RouterLink>
      <button v-if="![403, 404].includes(erreurChargement.status)" class="btn" type="button" @click="charger">Réessayer</button>
    </div>
  </div>

  <template v-else-if="ticket">
    <div class="entete">
      <div>
        <small class="reference">{{ ticket.reference }}</small>
        <h1>{{ ticket.titre }}</h1>
        <p>
          <PriorityBadge :priorite="ticket.priorite" />
          <span v-if="ticket.en_retard" class="retard"> · En retard depuis le {{ formatDate(ticket.echeance) }}</span>
        </p>
      </div>
      <RouterLink class="btn btn-discret" to="/tickets">← Tous les tickets</RouterLink>
    </div>

    <StatusRail :statut="ticket.statut" :peut-changer="auth.estAgent" :en-cours="transitionEnCours" @changer="changerStatut" />
    <div v-if="alerteTransition" class="alerte alerte-attention" role="alert" style="margin-top: 1rem">{{ alerteTransition }}</div>

    <div class="deux-colonnes" style="margin-top: 1rem">
      <div>
        <section class="section section-pale" aria-labelledby="titre-description">
          <div class="ligne" style="justify-content: space-between; margin-bottom: 0.9rem">
            <h2 id="titre-description">Description</h2>
            <button v-if="peutModifier && !edition" class="btn btn-petit" type="button" @click="ouvrirEdition">Modifier</button>
          </div>

          <p v-if="!edition" class="description">{{ ticket.description }}</p>

          <form v-else novalidate style="max-width: 640px" @submit.prevent="enregistrer">
            <div v-if="fEdition.message.value" class="alerte alerte-erreur" role="alert">{{ fEdition.message.value }}</div>
            <div class="champ" :class="{ invalide: fEdition.champ('titre') }">
              <label for="e-titre">Titre</label>
              <input id="e-titre" v-model="brouillon.titre" type="text" maxlength="150" />
              <p v-if="fEdition.champ('titre')" class="erreur-champ">{{ fEdition.champ('titre') }}</p>
            </div>
            <div class="champ" :class="{ invalide: fEdition.champ('description') }">
              <label for="e-desc">Description</label>
              <textarea id="e-desc" v-model="brouillon.description" />
              <p v-if="fEdition.champ('description')" class="erreur-champ">{{ fEdition.champ('description') }}</p>
            </div>
            <div class="ligne-champs">
              <div class="champ">
                <label for="e-prio">Priorité</label>
                <select id="e-prio" v-model="brouillon.priorite"><option v-for="p in PRIORITES" :key="p" :value="p">{{ LIBELLE_PRIORITE[p] }}</option></select>
              </div>
              <div class="champ" :class="{ invalide: fEdition.champ('echeance') }">
                <label for="e-ech">Échéance</label>
                <input id="e-ech" v-model="brouillon.echeance" type="date" :min="min" />
                <p v-if="fEdition.champ('echeance')" class="erreur-champ">{{ fEdition.champ('echeance') }}</p>
              </div>
            </div>
            <div class="ligne">
              <button class="btn btn-principal" type="submit" :disabled="fEdition.enCours.value">Enregistrer</button>
              <button class="btn btn-discret" type="button" @click="edition = false">Annuler</button>
            </div>
          </form>
        </section>

        <AttachmentsPanel :ticket-id="id" :pieces="ticket.pieces_jointes ?? []" @change="rafraichir" />
        <CommentsPanel :ticket-id="id" :ferme="ferme" />
      </div>

      <aside>
        <section class="section section-pale" aria-labelledby="titre-fiche">
          <h2 id="titre-fiche">Informations</h2>
          <dl class="fiche">
            <dt>Auteur</dt><dd>{{ ticket.auteur?.name }}</dd>
            <dt>Agent</dt><dd>{{ ticket.agent?.name ?? 'Non assigné' }}</dd>
            <dt>Échéance</dt><dd :class="{ retard: ticket.en_retard }">{{ formatDate(ticket.echeance) }}</dd>
            <dt>Créé le</dt><dd>{{ formatDateHeure(ticket.cree_le) }}</dd>
            <dt>Modifié le</dt><dd>{{ formatDateHeure(ticket.modifie_le) }}</dd>
            <template v-if="ticket.resolu_le"><dt>Résolu le</dt><dd>{{ formatDateHeure(ticket.resolu_le) }}</dd></template>
          </dl>
        </section>

        <section v-if="auth.estAgent && !ferme" class="section section-pale" aria-labelledby="titre-assign">
          <h2 id="titre-assign">Assignation</h2>
          <div class="champ">
            <label for="agent">Agent responsable</label>
            <select id="agent" v-model="assignation.valeur" :disabled="assignation.enCours">
              <option value="" disabled>Choisir un agent…</option>
              <option v-for="a in agents" :key="a.id" :value="String(a.id)">{{ a.name }}</option>
            </select>
          </div>
          <div class="ligne">
            <button class="btn btn-petit btn-principal" type="button" :disabled="assignation.enCours || !assignation.valeur || assignation.valeur === String(ticket.agent?.id ?? '')" @click="assigner">Assigner</button>
            <button v-if="ticket.agent" class="btn btn-petit" type="button" :disabled="assignation.enCours" @click="retirerAssignation">Retirer</button>
          </div>
        </section>

        <section class="section section-pale" aria-labelledby="titre-tags">
          <h2 id="titre-tags">Tags</h2>
          <template v-if="peutModifier">
            <div v-if="tagsDisponibles.length" class="ligne" style="gap: 0.4rem 1rem">
              <label v-for="t in tagsDisponibles" :key="t.id" class="coche"><input v-model="tagsChoisis" type="checkbox" :value="t.id" /> {{ t.nom }}</label>
            </div>
            <p v-else class="aide">Aucun tag n'existe encore.</p>
            <div v-if="fTags.message.value" class="alerte alerte-erreur" role="alert" style="margin-top: 0.5rem">{{ fTags.message.value }}</div>
            <button v-if="tagsModifies" class="btn btn-petit btn-principal" type="button" style="margin-top: 0.6rem" :disabled="fTags.enCours.value" @click="enregistrerTags">Enregistrer les tags</button>
          </template>
          <div v-else-if="ticket.tags?.length" class="ligne" style="gap: 0.3rem"><TagChip v-for="t in ticket.tags" :key="t.id" :tag="t" /></div>
          <p v-else class="discret">Aucun tag.</p>
        </section>

        <section v-if="ticket.permissions?.supprimer" class="section section-pale" aria-labelledby="titre-danger">
          <h2 id="titre-danger">Supprimer</h2>
          <p class="aide">Le ticket est placé dans la corbeille ; un administrateur peut le restaurer.</p>
          <ConfirmButton libelle="Supprimer le ticket" @confirme="supprimer" />
        </section>
      </aside>
    </div>
  </template>
</template>
