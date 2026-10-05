import { createApp } from 'vue'
import { createPinia } from 'pinia'
import '@fontsource-variable/public-sans'
import './style.css'
import App from './App.vue'
import { router } from './router'
import { configurerApi } from './api/client'
import { useAuthStore } from './stores/auth'
import { useToastStore } from './stores/toast'

const pinia = createPinia()
const app = createApp(App)
app.use(pinia)

const auth = useAuthStore(pinia)
const toasts = useToastStore(pinia)

// Le client HTTP lit le jeton dans le store, et réagit à un 401 (jeton expiré ou révoqué) : retour à la connexion
configurerApi({
  getToken: () => auth.token,
  onUnauthorized: () => {
    auth.effacer()
    toasts.erreur('Votre session a expiré. Reconnectez-vous.')
    router.push({ name: 'connexion', query: { redirect: router.currentRoute.value.fullPath } })
  },
})

app.use(router)
app.mount('#app')
