import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'

// En développement, les appels /api/... sont relayés vers Laravel (php artisan serve, port 8000) :
// le navigateur croit parler à sa propre origine, donc aucun souci de CORS.
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const cible = env.VITE_PROXY_TARGET || 'http://127.0.0.1:8000'
  return {
    plugins: [vue()],
    server: {
      port: 5173,
      proxy: { '/api': { target: cible, changeOrigin: true } },
    },
    preview: {
      port: 4173,
      proxy: { '/api': { target: cible, changeOrigin: true } },
    },
  }
})
