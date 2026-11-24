import { createHash } from 'node:crypto'
import { readFileSync } from 'node:fs'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

const backend = process.env.ZENLI_BACKEND_URL || 'http://127.0.0.1:8080'

const agendaSinConexion = {
  name: 'agenda-sin-conexion',
  apply: 'build',
  generateBundle: {
    order: 'post',
    handler(_, archivos) {
      const recursos = Object.keys(archivos).filter(nombre => nombre.startsWith('assets/')).sort().map(nombre => '/' + nombre)
      const plantilla = readFileSync(new URL('./agenda-sw.js', import.meta.url), 'utf8')
      const version = createHash('sha256').update(plantilla + JSON.stringify(recursos)).digest('hex').slice(0, 16)
      this.emitFile({
        type: 'asset',
        fileName: 'admin/agenda-sw.js',
        source: plantilla.replace('__CACHE_AGENDA__', 'zenli-agenda-' + version)
          .replace('__RECURSOS_AGENDA__', JSON.stringify(recursos)),
      })
    },
  },
}

export default defineConfig({
  plugins: [vue(), agendaSinConexion],
  server: {
    proxy: {
      '/api': backend,
      '/health': backend,
    },
  },
})
