import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toast'

const routes = [
  { path: '/', redirect: '/tickets' },
  { path: '/connexion', name: 'connexion', component: () => import('../views/LoginView.vue'), meta: { invite: true, titre: 'Connexion' } },
  { path: '/inscription', name: 'inscription', component: () => import('../views/RegisterView.vue'), meta: { invite: true, titre: 'Créer un compte' } },
  { path: '/tickets', name: 'tickets', component: () => import('../views/TicketsView.vue'), meta: { auth: true, titre: 'Tickets' } },
  { path: '/tickets/nouveau', name: 'ticket-nouveau', component: () => import('../views/TicketFormView.vue'), meta: { auth: true, titre: 'Nouveau ticket' } },
  { path: '/tickets/:id(\\d+)', name: 'ticket', component: () => import('../views/TicketDetailView.vue'), props: true, meta: { auth: true, titre: 'Ticket' } },
  { path: '/tags', name: 'tags', component: () => import('../views/TagsView.vue'), meta: { auth: true, role: 'agent', titre: 'Tags' } },
  { path: '/statistiques', name: 'statistiques', component: () => import('../views/StatsView.vue'), meta: { auth: true, role: 'agent', titre: 'Statistiques' } },
  { path: '/corbeille', name: 'corbeille', component: () => import('../views/TrashView.vue'), meta: { auth: true, role: 'admin', titre: 'Corbeille' } },
  { path: '/utilisateurs', name: 'utilisateurs', component: () => import('../views/UsersView.vue'), meta: { auth: true, role: 'admin', titre: 'Comptes' } },
  { path: '/profil', name: 'profil', component: () => import('../views/ProfileView.vue'), meta: { auth: true, titre: 'Mon compte' } },
  { path: '/:reste(.*)*', name: 'introuvable', component: () => import('../views/NotFoundView.vue'), meta: { titre: 'Page introuvable' } },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: (to, from, saved) => saved || { top: 0 },
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  try {
    await auth.initialiser()
  } catch {
    // serveur injoignable au démarrage : on laisse la page afficher son propre message d'erreur
  }

  if (to.meta.auth && !auth.estConnecte) {
    return { name: 'connexion', query: { redirect: to.fullPath } }
  }
  if (to.meta.invite && auth.estConnecte) {
    return { name: 'tickets' }
  }
  if (to.meta.role === 'agent' && !auth.estAgent) {
    useToastStore().erreur('Cette page est réservée aux agents.')
    return { name: 'tickets' }
  }
  if (to.meta.role === 'admin' && !auth.estAdmin) {
    useToastStore().erreur('Cette page est réservée aux administrateurs.')
    return { name: 'tickets' }
  }
})

router.afterEach((to) => {
  document.title = (to.meta.titre ? to.meta.titre + ' · ' : '') + 'Helpdesk'
})
