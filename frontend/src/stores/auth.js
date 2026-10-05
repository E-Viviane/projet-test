import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { get, post } from '../api/client'

const CLE = 'helpdesk.jeton'

// sessionStorage : le jeton disparaît à la fermeture de l'onglet (localStorage le garderait, au prix d'une
// exposition plus longue en cas de faille XSS). Les accès sont entourés de try/catch : certains navigateurs les bloquent.
function lire() {
  try { return sessionStorage.getItem(CLE) } catch { return null }
}
function ecrire(valeur) {
  try { valeur ? sessionStorage.setItem(CLE, valeur) : sessionStorage.removeItem(CLE) } catch { /* ignoré */ }
}

export const useAuthStore = defineStore('auth', () => {
  const token = ref(lire())
  const user = ref(null)
  const chargementInitial = ref(false)

  const estConnecte = computed(() => !!token.value)
  const estAgent = computed(() => ['agent', 'admin'].includes(user.value?.role))
  const estAdmin = computed(() => user.value?.role === 'admin')

  function ouvrirSession(reponse) {
    token.value = reponse.token
    user.value = reponse.data
    ecrire(reponse.token)
  }

  function effacer() {
    token.value = null
    user.value = null
    ecrire(null)
  }

  async function connexion(email, password) {
    ouvrirSession(await post('/auth/connexion', { email, password, appareil: 'navigateur-vue' }))
  }

  async function inscription(donnees) {
    ouvrirSession(await post('/auth/inscription', { ...donnees, appareil: 'navigateur-vue' }))
  }

  async function deconnexion() {
    try { await post('/auth/deconnexion') } catch { /* jeton déjà invalide : on déconnecte quand même */ }
    effacer()
  }

  /** Au chargement de la page : on a un jeton en mémoire de session mais pas encore le profil. */
  async function initialiser() {
    if (!token.value || user.value) return
    chargementInitial.value = true
    try {
      user.value = (await get('/auth/moi')).data
    } catch (e) {
      if (e.status === 401) effacer()
      else throw e
    } finally {
      chargementInitial.value = false
    }
  }

  return { token, user, estConnecte, estAgent, estAdmin, chargementInitial, connexion, inscription, deconnexion, initialiser, effacer }
})
