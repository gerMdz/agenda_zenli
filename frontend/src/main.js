import { createApp } from 'vue'
import PrimeVue from 'primevue/config'
import Aura from '@primeuix/themes/aura'
import App from './App.vue'
import './base.css'

function agregarMetadatosAgenda() {
  for (const [rel, archivo] of [
    ['manifest', 'manifest.webmanifest'],
    ['apple-touch-icon', 'loto-180.png'],
  ]) {
    const enlace = document.createElement('link')
    enlace.rel = rel
    enlace.href = '/assets/agenda/' + archivo
    document.head.append(enlace)
  }
}

async function iniciar() {
  const ruta = window.location.pathname.replace(/\/$/, '')
  if (ruta === '/demo') {
    document.title = 'Página no disponible · Zenli'
    document.querySelector('#app').textContent = 'Esta página no está disponible.'
    return
  }
  let vista = App
  if (ruta === '/admin' || ruta === '/admin/agenda') agregarMetadatosAgenda()

  if (ruta === '/admin') {
    vista = (await import('./Administracion.vue')).default
  } else if (ruta === '/admin/agenda') {
    vista = (await import('./AgendaZenli.vue')).default
  }

  createApp(vista)
    .use(PrimeVue, {
      theme: {
        preset: Aura,
        options: { darkModeSelector: false },
      },
    })
    .mount('#app')
}

iniciar()
