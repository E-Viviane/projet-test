<script setup>
import { LIBELLE_PRIORITE } from '../utils/libelles'
defineProps({ priorite: { type: String, required: true } })
</script>

<template>
  <span class="pastille" :class="'p-' + priorite">{{ LIBELLE_PRIORITE[priorite] ?? priorite }}</span>
</template>
