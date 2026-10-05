<script setup>
defineProps({ tag: { type: Object, required: true } })
</script>

<template>
  <span class="etiquette-tag" :style="{ '--tag': tag.couleur }">{{ tag.nom }}</span>
</template>
