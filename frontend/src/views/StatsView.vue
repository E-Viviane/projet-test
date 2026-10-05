<script setup>
import { computed, onMounted, ref } from 'vue'
import { get } from '../api/client'
import { texteErreur } from '../utils/erreurs'
import { formatDate } from '../utils/format'
import { LIBELLE_PRIORITE, LIBELLE_STATUT, PRIORITES, STATUTS } from '../utils/libelles'

const stats = ref(null)
const chargement = ref(true)
const erreur = ref(null)

async function charger() {
  chargement.value = true
  erreur.value = null
  try {
    stats.value = (await get('/statistiques')).data
  } catch (e) {
    erreur.value = texteErreur(e)
  } finally {
    chargement.value = false
  }
}
onMounted(charger)

const COULEUR_STATUT = { nouveau: '#285d8c', en_cours: '#0b6b47', en_attente: '#c78a14', resolu: '#2f8f74', ferme: '#8b968f' }
const COULEUR_PRIORITE = { basse: '#a9b3ac', normale: '#6b9bc4', haute: '#d99a1c', critique: '#b3261e' }

const maxStatut = computed(() => Math.max(1, ...Object.values(stats.value?.par_statut ?? {})))
const maxPriorite = computed(() => Math.max(1, ...Object.values(stats.value?.par_priorite ?? {})))
const maxJour = computed(() => Math.max(1, ...(stats.value?.crees_30_jours ?? []).map((j) => j.total)))
const totalJours = computed(() => (stats.value?.crees_30_jours ?? []).reduce((s, j) => s + j.total, 0))
const ouverts = computed(() => (stats.value ? stats.value.total - stats.value.par_statut.resolu - stats.value.par_statut.ferme : 0))
</script>

<template>
  <div class="entete">
    <div>
      <h1>Statistiques</h1>
      <p>Vue d'ensemble de la charge de travail.</p>
    </div>
  </div>

  <p v-if="chargement" class="discret">Chargement…</p>
  <div v-else-if="erreur" class="alerte alerte-erreur" role="alert">{{ erreur }} <button class="btn btn-petit" type="button" @click="charger">Réessayer</button></div>

  <template v-else-if="stats">
    <div class="chiffres">
      <div class="chiffre"><strong data-stat="total">{{ stats.total }}</strong><span class="discret">tickets au total</span></div>
      <div class="chiffre"><strong data-stat="ouverts">{{ ouverts }}</strong><span class="discret">encore ouverts</span></div>
      <div class="chiffre"><strong data-stat="retard" :class="{ retard: stats.en_retard }">{{ stats.en_retard }}</strong><span class="discret">en retard</span></div>
    </div>

    <div class="deux-colonnes" style="grid-template-columns: 1fr 1fr">
      <section class="section" aria-labelledby="t-statut">
        <h2 id="t-statut">Par statut</h2>
        <div class="barres">
          <div v-for="s in STATUTS" :key="s" class="barre">
            <span>{{ LIBELLE_STATUT[s] }}</span>
            <div class="barre-piste"><div class="barre-remplie" :style="{ width: (stats.par_statut[s] / maxStatut) * 100 + '%', '--couleur': COULEUR_STATUT[s] }" /></div>
            <span class="numerique">{{ stats.par_statut[s] }}</span>
          </div>
        </div>
      </section>

      <section class="section" aria-labelledby="t-prio">
        <h2 id="t-prio">Par priorité</h2>
        <div class="barres">
          <div v-for="p in PRIORITES" :key="p" class="barre">
            <span>{{ LIBELLE_PRIORITE[p] }}</span>
            <div class="barre-piste"><div class="barre-remplie" :style="{ width: (stats.par_priorite[p] / maxPriorite) * 100 + '%', '--couleur': COULEUR_PRIORITE[p] }" /></div>
            <span class="numerique">{{ stats.par_priorite[p] }}</span>
          </div>
        </div>
      </section>
    </div>

    <section class="section" aria-labelledby="t-jours">
      <h2 id="t-jours">Tickets créés ces 30 derniers jours <small>({{ totalJours }})</small></h2>
      <p v-if="!stats.crees_30_jours.length" class="discret">Aucun ticket créé sur la période.</p>
      <template v-else>
        <div class="colonnes-jours" role="img" :aria-label="`${totalJours} tickets créés sur 30 jours`">
          <div v-for="j in stats.crees_30_jours" :key="j.jour" class="colonne-jour" :style="{ height: (j.total / maxJour) * 100 + '%' }" :title="`${formatDate(j.jour)} : ${j.total}`" />
        </div>
        <small class="discret">Chaque colonne représente un jour ; passez la souris pour voir la date et le nombre.</small>
      </template>
    </section>

    <section class="section" aria-labelledby="t-agents">
      <h2 id="t-agents">Charge des agents</h2>
      <p v-if="!stats.charge_agents.length" class="discret">Aucun ticket ouvert n'est assigné.</p>
      <div v-else class="tableau-enveloppe" style="max-width: 460px">
        <table>
          <thead><tr><th>Agent</th><th class="numerique">Tickets ouverts</th></tr></thead>
          <tbody><tr v-for="a in stats.charge_agents" :key="a.agent"><td>{{ a.agent }}</td><td class="numerique">{{ a.tickets_ouverts }}</td></tr></tbody>
        </table>
      </div>
    </section>
  </template>
</template>
