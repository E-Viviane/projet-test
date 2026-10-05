<script setup>
import { computed } from 'vue'

// Barre de pagination pour les réponses Laravel : meta = { current_page, last_page, per_page, total, from, to }
const props = defineProps({
  meta: { type: Object, required: true },
  tailles: { type: Array, default: () => [10, 15, 25, 50] },
})
const emit = defineEmits(['page', 'taille'])

const pages = computed(() => {
  const { current_page: c, last_page: d } = props.meta
  const debut = Math.max(1, Math.min(c - 2, d - 4))
  const fin = Math.min(d, debut + 4)
  return Array.from({ length: fin - debut + 1 }, (_, i) => debut + i)
})
</script>

<template>
  <div class="pagination">
    <small v-if="meta.total > 0">{{ meta.from }}–{{ meta.to }} sur {{ meta.total }}</small>
    <small v-else>Aucun résultat</small>

    <nav v-if="meta.last_page > 1" aria-label="Pagination">
      <button class="btn btn-petit" type="button" :disabled="meta.current_page <= 1" @click="emit('page', meta.current_page - 1)">Précédent</button>
      <button
        v-for="p in pages" :key="p" type="button" class="btn btn-petit"
        :aria-current="p === meta.current_page ? 'page' : undefined" :aria-label="'Page ' + p"
        @click="emit('page', p)"
      >{{ p }}</button>
      <button class="btn btn-petit" type="button" :disabled="meta.current_page >= meta.last_page" @click="emit('page', meta.current_page + 1)">Suivant</button>
    </nav>

    <label class="coche">
      <small>Par page</small>
      <select :value="meta.per_page" style="width: auto" @change="emit('taille', Number($event.target.value))">
        <option v-for="t in tailles" :key="t" :value="t">{{ t }}</option>
      </select>
    </label>
  </div>
</template>
