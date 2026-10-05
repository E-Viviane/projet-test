// Vocabulaire de l'interface et règles métier miroirs de l'API (Support/Transitions.php côté Laravel).

export const STATUTS = ['nouveau', 'en_cours', 'en_attente', 'resolu', 'ferme']
export const PRIORITES = ['basse', 'normale', 'haute', 'critique']

export const LIBELLE_STATUT = {
  nouveau: 'Nouveau',
  en_cours: 'En cours',
  en_attente: 'En attente',
  resolu: 'Résolu',
  ferme: 'Fermé',
}

export const LIBELLE_PRIORITE = { basse: 'Basse', normale: 'Normale', haute: 'Haute', critique: 'Critique' }
export const LIBELLE_ROLE = { user: 'Utilisateur', agent: 'Agent', admin: 'Administrateur' }

/** Transitions autorisées (l'API reste l'autorité : elle répond 409 si on triche). */
export const TRANSITIONS = {
  nouveau: ['en_cours', 'ferme'],
  en_cours: ['en_attente', 'resolu'],
  en_attente: ['en_cours', 'resolu'],
  resolu: ['ferme', 'en_cours'],
  ferme: [],
}

export const TRIS = [
  { valeur: '-created_at', libelle: 'Plus récents d\'abord' },
  { valeur: 'created_at', libelle: 'Plus anciens d\'abord' },
  { valeur: '-priorite,-created_at', libelle: 'Priorité la plus haute d\'abord' },
  { valeur: 'echeance,-created_at', libelle: 'Échéance la plus proche' },
  { valeur: 'titre', libelle: 'Titre (A → Z)' },
]
