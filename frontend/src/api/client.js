// Client HTTP unique de l'application. Toutes les vues passent par ici : un seul endroit pour
// l'en-tête Authorization, le format des erreurs, le 401 (jeton expiré) et les erreurs réseau.

const BASE = import.meta.env.VITE_API_URL || '/api/v1'

let lireJeton = () => null
let surNonAuthentifie = () => {}

/** Branché au démarrage (main.js) pour éviter une dépendance circulaire avec le store d'authentification. */
export function configurerApi({ getToken, onUnauthorized }) {
  lireJeton = getToken
  surNonAuthentifie = onUnauthorized
}

/** Erreur normalisée : status HTTP, code stable de l'API, erreurs par champ (422), Retry-After (429). */
export class ApiError extends Error {
  constructor(status, corps = {}, retryAfter = null) {
    super(corps.message || 'Une erreur est survenue.')
    this.name = 'ApiError'
    this.status = status
    this.code = corps.code || null
    this.errors = corps.errors || {}
    this.body = corps
    this.retryAfter = retryAfter
  }

  /** Premier message d'un champ : erreur.champ('titre') */
  champ(nom) {
    const liste = this.errors[nom]
    return Array.isArray(liste) ? liste[0] : null
  }
}

function construireUrl(chemin, query) {
  const url = new URL(BASE + chemin, window.location.origin)
  for (const [cle, valeur] of Object.entries(query || {})) {
    if (valeur === null || valeur === undefined || valeur === '') continue
    url.searchParams.set(cle, String(valeur))
  }
  return url.pathname + url.search
}

/**
 * api('GET', '/tickets', { query, body, form, headers, signal, blob })
 *  - body : objet JSON ; form : FormData (upload) ; blob : renvoyer la réponse binaire (téléchargement)
 *  - 204 -> null ; erreur -> ApiError
 */
export async function api(methode, chemin, { query, body, form, headers = {}, signal, blob = false } = {}) {
  const entetes = { Accept: 'application/json', ...headers }
  const jeton = lireJeton()
  if (jeton) entetes.Authorization = `Bearer ${jeton}`

  let corps
  if (form) {
    corps = form // le navigateur fixe lui-même Content-Type + boundary
  } else if (body !== undefined) {
    entetes['Content-Type'] = 'application/json'
    corps = JSON.stringify(body)
  }

  let reponse
  try {
    reponse = await fetch(construireUrl(chemin, query), { method: methode, headers: entetes, body: corps, signal })
  } catch (e) {
    if (e.name === 'AbortError') throw e // requête annulée volontairement : pas une erreur à afficher
    throw new ApiError(0, { message: "Impossible de joindre le serveur. Vérifiez que l'API est démarrée.", code: 'reseau' })
  }

  if (reponse.ok) {
    if (reponse.status === 204) return null
    if (blob) return reponse
    return reponse.json()
  }

  let contenu = {}
  try {
    contenu = await reponse.json()
  } catch {
    contenu = { message: `Erreur ${reponse.status}` }
  }
  const erreur = new ApiError(reponse.status, contenu, Number(reponse.headers.get('Retry-After')) || null)

  // 401 sur une route protégée alors qu'on avait un jeton : il a expiré ou a été révoqué
  if (reponse.status === 401 && jeton && contenu.code === 'non_authentifie') surNonAuthentifie()
  throw erreur
}

export const get = (chemin, options) => api('GET', chemin, options)
export const post = (chemin, body, options) => api('POST', chemin, { ...options, body })
export const put = (chemin, body, options) => api('PUT', chemin, { ...options, body })
export const patch = (chemin, body, options) => api('PATCH', chemin, { ...options, body })
export const del = (chemin, options) => api('DELETE', chemin, options)

/** Télécharge un fichier protégé (l'en-tête Authorization interdit un simple lien <a href>). */
export async function telecharger(chemin, nomParDefaut, query) {
  const reponse = await api('GET', chemin, { blob: true, query })
  const disposition = reponse.headers.get('Content-Disposition') || ''
  const trouve = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)
  const nom = trouve ? decodeURIComponent(trouve[1]) : nomParDefaut
  const url = URL.createObjectURL(await reponse.blob())
  const lien = document.createElement('a')
  lien.href = url
  lien.download = nom
  document.body.appendChild(lien)
  lien.click()
  lien.remove()
  setTimeout(() => URL.revokeObjectURL(url), 2000)
}
