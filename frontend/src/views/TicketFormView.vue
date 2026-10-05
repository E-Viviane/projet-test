<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { get, post } from '../api/client'
import { useToastStore } from '../stores/toast'
import { cleIdempotence, aujourdhui } from '../utils/format'
import { useFormulaire } from '../utils/erreurs'
import { LIBELLE_PRIORITE, PRIORITES } from '../utils/libelles'

const router = useRouter()
const toasts = useToastStore()
const f = useFormulaire()

const form = reactive({ titre: '', description: '', priorite: 'normale', echeance: '', tags: [] })
const tagsDisponibles = ref([])
const min = aujourdhui()

// Une même clé d'idempotence tant que le formulaire n'a pas changé : si la réponse se perd (réseau coupé)
// et que l'utilisateur renvoie, l'API ne crée PAS un deuxième ticket.
let cle = cleIdempotence()

onMounted(async () => {
  try {
    tagsDisponibles.value = (await get('/tags')).data
  } catch { /* les tags sont facultatifs : le formulaire reste utilisable */ }
})

async function envoyer() {
  const corps = {
    titre: form.titre.trim(),
    description: form.description.trim(),
    priorite: form.priorite,
    echeance: form.echeance || null,
    tags: form.tags,
  }
  const rep = await f.soumettre(() => post('/tickets', corps, { headers: { 'Idempotency-Key': cle } }))
  if (!rep) return
  toasts.succes(`Ticket ${rep.data.reference} créé.`)
  router.push({ name: 'ticket', params: { id: rep.data.id } })
}

function modifie() { cle = cleIdempotence() } // contenu différent = nouvelle demande
</script>

<template>
  <div class="entete">
    <div>
      <h1>Nouveau ticket</h1>
      <p>Décrivez le problème pour que l'équipe puisse le traiter sans vous rappeler.</p>
    </div>
  </div>

  <div v-if="f.message.value" class="alerte alerte-erreur" role="alert">{{ f.message.value }}</div>

  <form novalidate style="max-width: 640px" @submit.prevent="envoyer" @input="modifie">
    <div class="champ" :class="{ invalide: f.champ('titre') }">
      <label for="titre">Titre</label>
      <input id="titre" v-model="form.titre" type="text" maxlength="150" required aria-describedby="aide-titre" />
      <p id="aide-titre" class="aide">En une phrase : ce qui ne fonctionne pas, et où.</p>
      <p v-if="f.champ('titre')" class="erreur-champ">{{ f.champ('titre') }}</p>
    </div>

    <div class="champ" :class="{ invalide: f.champ('description') }">
      <label for="description">Description</label>
      <textarea id="description" v-model="form.description" required />
      <p v-if="f.champ('description')" class="erreur-champ">{{ f.champ('description') }}</p>
    </div>

    <div class="ligne-champs">
      <div class="champ" :class="{ invalide: f.champ('priorite') }">
        <label for="priorite">Priorité</label>
        <select id="priorite" v-model="form.priorite">
          <option v-for="p in PRIORITES" :key="p" :value="p">{{ LIBELLE_PRIORITE[p] }}</option>
        </select>
        <p v-if="f.champ('priorite')" class="erreur-champ">{{ f.champ('priorite') }}</p>
      </div>
      <div class="champ" :class="{ invalide: f.champ('echeance') }">
        <label for="echeance">Échéance (facultatif)</label>
        <input id="echeance" v-model="form.echeance" type="date" :min="min" />
        <p v-if="f.champ('echeance')" class="erreur-champ">{{ f.champ('echeance') }}</p>
      </div>
    </div>

    <fieldset v-if="tagsDisponibles.length" class="champ" style="border: 0; padding: 0; margin: 0 0 1rem">
      <legend class="etiquette" style="padding: 0; margin-bottom: 0.3rem">Tags</legend>
      <div class="ligne">
        <label v-for="t in tagsDisponibles" :key="t.id" class="coche">
          <input v-model="form.tags" type="checkbox" :value="t.id" /> {{ t.nom }}
        </label>
      </div>
    </fieldset>

    <div class="ligne">
      <button class="btn btn-principal" type="submit" :disabled="f.enCours.value">{{ f.enCours.value ? 'Création…' : 'Créer le ticket' }}</button>
      <RouterLink class="btn btn-discret" to="/tickets">Annuler</RouterLink>
    </div>
  </form>
</template>
