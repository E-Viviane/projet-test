const dateHeure = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' })
const dateSeule = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium' })

/** « 2026-10-05T14:30:00+00:00 » -> « 5 oct. 2026, 15:30 » (fuseau du navigateur) */
export function formatDateHeure(iso) {
  return iso ? dateHeure.format(new Date(iso)) : '—'
}

/** « 2026-10-05 » -> « 5 oct. 2026 ». Une date sans heure ne doit PAS passer par le fuseau (sinon elle peut reculer d'un jour). */
export function formatDate(ymd) {
  if (!ymd) return '—'
  const [a, m, j] = ymd.split('-').map(Number)
  return dateSeule.format(new Date(a, m - 1, j))
}

export function aujourdhui() {
  const d = new Date()
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

export function formatTaille(octets) {
  if (octets < 1024) return `${octets} o`
  if (octets < 1024 * 1024) return `${Math.round(octets / 1024)} Ko`
  return `${(octets / 1024 / 1024).toFixed(1)} Mo`
}

/** Identifiant unique pour l'en-tête Idempotency-Key. */
export function cleIdempotence() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID()
  return 'cle-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10)
}

export function debounce(fn, delai = 350) {
  let t
  const f = (...args) => {
    clearTimeout(t)
    t = setTimeout(() => fn(...args), delai)
  }
  f.annuler = () => clearTimeout(t)
  return f
}
