<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'
import { useToastStore } from './stores/toast'
import { LIBELLE_ROLE } from './utils/libelles'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()

const avecMenu = computed(() => auth.estConnecte && !route.meta.invite)

async function sortir() {
  await auth.deconnexion()
  toasts.succes('Vous êtes déconnecté.')
  router.push({ name: 'connexion' })
}
</script>

<template>
  <a class="lien-evitement" href="#contenu">Aller au contenu</a>

  <div v-if="avecMenu" class="coque">
    <aside class="menu">
      <RouterLink class="marque" to="/tickets">
        <svg viewBox="0 0 32 32" width="28" height="28" aria-hidden="true">
          <rect width="32" height="32" rx="7" fill="currentColor" />
          <path d="M9 17l5 5 9-11" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        <span>Helpdesk</span>
      </RouterLink>

      <nav aria-label="Navigation principale">
        <RouterLink to="/tickets">Tickets</RouterLink>
        <RouterLink to="/tickets/nouveau">Nouveau ticket</RouterLink>
        <template v-if="auth.estAgent">
          <RouterLink to="/statistiques">Statistiques</RouterLink>
          <RouterLink to="/tags">Tags</RouterLink>
        </template>
        <template v-if="auth.estAdmin">
          <RouterLink to="/corbeille">Corbeille</RouterLink>
          <RouterLink to="/utilisateurs">Comptes</RouterLink>
        </template>
      </nav>

      <div class="menu-pied">
        <RouterLink class="moi" to="/profil">
          <strong>{{ auth.user?.name }}</strong>
          <small>{{ LIBELLE_ROLE[auth.user?.role] }}</small>
        </RouterLink>
        <button class="btn btn-discret" type="button" @click="sortir">Se déconnecter</button>
      </div>
    </aside>

    <main id="contenu" class="contenu" tabindex="-1">
      <RouterView />
    </main>
  </div>

  <main v-else id="contenu" class="plein-ecran" tabindex="-1">
    <RouterView />
  </main>

  <div class="toasts" aria-live="polite">
    <div v-for="m in toasts.messages" :key="m.id" class="toast" :class="'toast-' + m.type" role="status">
      <span>{{ m.texte }}</span>
      <button type="button" class="toast-fermer" aria-label="Fermer le message" @click="toasts.fermer(m.id)">×</button>
    </div>
  </div>
</template>
