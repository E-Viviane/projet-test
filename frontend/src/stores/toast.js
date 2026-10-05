import { defineStore } from 'pinia'
import { ref } from 'vue'

/** Messages brefs (succès / erreur) affichés en bas de l'écran. */
export const useToastStore = defineStore('toast', () => {
  const messages = ref([])
  let suivant = 1

  function afficher(texte, type = 'succes', duree = 4500) {
    const id = suivant++
    messages.value.push({ id, texte, type })
    if (duree) setTimeout(() => fermer(id), duree)
  }
  function fermer(id) {
    messages.value = messages.value.filter((m) => m.id !== id)
  }
  const succes = (t) => afficher(t, 'succes')
  const erreur = (t) => afficher(t, 'erreur', 8000)

  return { messages, afficher, fermer, succes, erreur }
})
