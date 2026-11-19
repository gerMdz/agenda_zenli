import { createApp } from 'vue'
import PrimeVue from 'primevue/config'
import Aura from '@primeuix/themes/aura'
import App from './App.vue'
import Demo from './Demo.vue'

const isDemo = ['/demo', '/demo/'].includes(window.location.pathname)

createApp(isDemo ? Demo : App)
  .use(PrimeVue, {
    theme: {
      preset: Aura,
      options: { darkModeSelector: false },
    },
  })
  .mount('#app')
