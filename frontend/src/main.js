import { createApp } from 'vue'
import PrimeVue from 'primevue/config'
import Aura from '@primeuix/themes/aura'
import App from './App.vue'
import './base.css'

async function iniciar() {
  const ruta = window.location.pathname.replace(/\/$/, '')
  let vista = App
  if (ruta === '/admin') {
    vista = (await import('./Administracion.vue')).default
  } else if (ruta === '/admin/agenda') {
    vista = (await import('./AgendaAdministrativa.vue')).default
  } else if (ruta === '/demo') {
    vista = (await import('./Demo.vue')).default
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
