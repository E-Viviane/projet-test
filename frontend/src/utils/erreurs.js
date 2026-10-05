import { ref, computed } from 'vue'
import { ApiError } from '../api/client'

/** Message lisible pour n'importe quelle erreur de l'API (jamais un message technique brut). */
export function texteErreur(e) {
  if (!(e instanceof ApiError)) return 'Une erreur inattendue est survenue.'
  switch (e.status) {
    case 0: return e.message
    case 403: return "Vous n'avez pas le droit d'effectuer cette action."
    case 404: return 'Cet élément est introuvable. Il a peut-être été supprimé.'
    case 429: return e.retryAfter ? `Trop de requêtes. Réessayez dans ${e.retryAfter} s.` : 'Trop de requêtes. Réessayez dans un instant.'
    case 500: case 502: case 503: return 'Le serveur rencontre un problème. Réessayez dans un instant.'
    default: return e.message // 401, 409, 422 : le message de l'API est déjà rédigé pour l'utilisateur
  }
}

/**
 * État d'un formulaire : « envoi en cours » (anti double clic), erreur globale, erreurs par champ (422).
 *   const f = useFormulaire()
 *   await f.soumettre(() => post('/tickets', corps))
 *   f.champ('titre')  ->  message à afficher sous le champ
 */
export function useFormulaire() {
  const enCours = ref(false)
  const erreur = ref(null)

  const message = computed(() => {
    if (!erreur.value) return null
    // En 422, les messages sont affichés sous chaque champ ; on ne garde un message global que s'il n'y a pas de détail
    return erreur.value.status === 422 && Object.keys(erreur.value.errors).length ? null : texteErreur(erreur.value)
  })

  function champ(nom) {
    return erreur.value?.champ?.(nom) || null
  }

  async function soumettre(action) {
    if (enCours.value) return undefined
    enCours.value = true
    erreur.value = null
    try {
      return await action()
    } catch (e) {
      if (e?.name === 'AbortError') return undefined
      erreur.value = e instanceof ApiError ? e : new ApiError(0, { message: 'Une erreur inattendue est survenue.' })
      return undefined
    } finally {
      enCours.value = false
    }
  }

  function reinitialiser() { erreur.value = null }

  return { enCours, erreur, message, champ, soumettre, reinitialiser }
}
