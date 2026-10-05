<script setup>
import { onBeforeUnmount, ref } from 'vue'

// Bouton à double clic pour les actions destructrices : 1er clic = « Confirmer ? », 2e clic = action.
// Sans confirmation au bout de 4 s, il revient à son état initial.
defineProps({
  libelle: { type: String, required: true },
  libelleConfirmation: { type: String, default: 'Confirmer la suppression' },
  desactive: { type: Boolean, default: false },
  petit: { type: Boolean, default: false },
})
const emit = defineEmits(['confirme'])

const attente = ref(false)
let minuteur = null

function cliquer() {
  if (!attente.value) {
    attente.value = true
    minuteur = setTimeout(() => (attente.value = false), 4000)
    return
  }
  clearTimeout(minuteur)
  attente.value = false
  emit('confirme')
}
onBeforeUnmount(() => clearTimeout(minuteur))
</script>

<template>
  <button type="button" class="btn btn-danger" :class="{ confirmer: attente, 'btn-petit': petit }" :disabled="desactive" @click="cliquer">
    {{ attente ? libelleConfirmation : libelle }}
  </button>
</template>
