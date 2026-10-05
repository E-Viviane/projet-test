<script setup>
import { LIBELLE_STATUT } from '../utils/libelles'
defineProps({ statut: { type: String, required: true } })
</script>

<template>
  <span class="pastille" :class="'s-' + statut">{{ LIBELLE_STATUT[statut] ?? statut }}</span>
</template>
