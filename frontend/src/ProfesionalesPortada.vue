<script setup>
import { computed, onMounted, ref } from 'vue'

const profesionales = ref([])
const mostrarImagenes = computed(() => profesionales.value.length > 0 && profesionales.value.every(persona => persona.imagen_version))
onMounted(async () => {
  try {
    const respuesta = await fetch('/api/profesionales-portada', { cache: 'no-store' })
    if (respuesta.ok) profesionales.value = (await respuesta.json()).profesionales
  } catch {
    profesionales.value = []
  }
})
</script>

<template>
  <section v-if="profesionales.length" class="profesionales-portada" aria-labelledby="titulo-profesionales">
    <h2 id="titulo-profesionales">Nuestro equipo</h2>
    <ul>
      <li v-for="profesional in profesionales" :key="profesional.id">
        <img v-if="mostrarImagenes" :src="`/api/profesionales-portada/${profesional.id}/imagen/${profesional.imagen_version}`" :alt="profesional.nombre">
        <span>{{ profesional.nombre }}</span>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.profesionales-portada { text-align: center; padding: 2rem 1rem; }
ul { display: flex; flex-wrap: wrap; justify-content: center; gap: 1.5rem; list-style: none; padding: 0; }
li { display: flex; flex-direction: column; align-items: center; gap: .5rem; }
img { width: 10rem; height: 10rem; object-fit: cover; border-radius: 50%; }
</style>