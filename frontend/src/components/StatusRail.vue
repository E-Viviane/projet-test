<script setup>
import { STATUTS, LIBELLE_STATUT, TRANSITIONS } from '../utils/libelles'

// Le « rail » montre où en est le ticket et, pour un agent, les seules étapes atteignables depuis cet état
// (les mêmes que celles que l'API accepte). Les autres restent grisées : on voit la règle métier au lieu de la deviner.
const props = defineProps({
  statut: { type: String, required: true },
  peutChanger: { type: Boolean, default: false },
  enCours: { type: Boolean, default: false },
})
const emit = defineEmits(['changer'])

const possible = (s) => TRANSITIONS[props.statut]?.includes(s)
</script>

<template>
  <ol class="rail" aria-label="Avancement du ticket">
    <li
      v-for="s in STATUTS" :key="s"
      :class="{ actuel: s === statut, possible: peutChanger && possible(s) }"
      :aria-current="s === statut ? 'step' : undefined"
    >
      <button v-if="peutChanger && possible(s)" type="button" :disabled="enCours" :data-vers="s" @click="emit('changer', s)">
        {{ LIBELLE_STATUT[s] }}
      </button>
      <template v-else>{{ LIBELLE_STATUT[s] }}</template>
      <small v-if="s === statut">état actuel</small>
      <small v-else-if="peutChanger && possible(s)">passer à cet état</small>
    </li>
  </ol>
</template>
