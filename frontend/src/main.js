import { createApp } from 'vue'
import PrimeVue from 'primevue/config'
import Aura from '@primeuix/themes/aura'
import App from './App.vue'
import './base.css'

async function iniciar() {
  const ruta = window.location.pathname.replace(/\/$/, '')
  if (ruta === '/demo') {
    document.title = 'Página no disponible · Zenli'
    document.querySelector('#app').textContent = 'Esta página no está disponible.'
    return
  }
  let vista = App
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
