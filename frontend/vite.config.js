import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

const backend = process.env.ZENLI_BACKEND_URL || 'http://127.0.0.1:8080'

export default defineConfig({
  plugins: [vue()],
  server: {
    proxy: {
      '/api': backend,
      '/health': backend,
    },
  },
})
