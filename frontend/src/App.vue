<script setup>
import { ref } from 'vue'
import Button from 'primevue/button'

const checking = ref(false)
const status = ref('')

async function checkBackend() {
  checking.value = true
  status.value = ''

  try {
    const response = await fetch('/health', {
      cache: 'no-store',
      signal: AbortSignal.timeout(8000),
    })

    if (!response.ok || (await response.json()).status !== 'ok') {
      throw new Error('Symfony no respondió correctamente')
    }

    status.value = 'Symfony está disponible.'
  } catch {
    status.value = 'No se pudo conectar con Symfony. Volvé a intentar.'
  } finally {
    checking.value = false
  }
}
</script>

<template>
  <main>
    <h1>Zenli</h1>
    <p>Centro de masoterapia · Mendoza</p>
    <Button label="Comprobar conexión" :loading="checking" @click="checkBackend" />
    <p role="status">{{ status }}</p>
  </main>
</template>

<style>
body {
  margin: 0;
  background: #f8f5ed;
  color: #183e32;
  font-family: system-ui, sans-serif;
}

main {
  width: min(100% - 3rem, 42rem);
  margin: 15vh auto 0;
}

h1 {
  margin: 0;
  font-size: clamp(3rem, 10vw, 6rem);
  font-weight: 500;
  letter-spacing: -0.05em;
}
</style>
