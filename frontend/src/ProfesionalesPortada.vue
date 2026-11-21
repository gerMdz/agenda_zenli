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
    <div class="contenido">
      <p class="etiqueta">Zenli</p>
      <h2 id="titulo-profesionales">Nuestro equipo</h2>
      <div class="adorno" aria-hidden="true">✿</div>
      <ul :class="{ 'con-imagenes': mostrarImagenes }">
        <li v-for="profesional in profesionales" :key="profesional.id">
          <img v-if="mostrarImagenes" :src="`/api/profesionales-portada/${profesional.id}/imagen/${profesional.imagen_version}`" alt="">
          <span>{{ profesional.nombre }}</span>
        </li>
      </ul>
    </div>
  </section>
</template>

<style scoped>
.profesionales-portada { padding: clamp(3rem, 6vw, 5rem) 1.5rem; border-block: 1px solid #e3ca91; background: #f8efdc; text-align: center; }
.contenido { max-width: 75rem; margin-inline: auto; }
.etiqueta { margin: 0 0 .45rem; color: #806024; font-size: .73rem; font-weight: 700; letter-spacing: .2em; text-transform: uppercase; }
h2 { margin: 0; color: #15372a; font: 500 clamp(2rem, 4vw, 2.8rem)/1.15 Georgia, serif; }
.adorno { display: flex; align-items: center; justify-content: center; gap: .7rem; margin: .8rem auto 1.8rem; color: #a57c35; font-size: 1rem; }
.adorno::before, .adorno::after { content: ''; width: 2.5rem; height: 1px; background: #d7bd85; }
ul { display: flex; flex-wrap: wrap; justify-content: center; gap: 1rem; margin: 0; padding: 0; list-style: none; }
li { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1rem; box-sizing: border-box; width: min(100%, 15rem); min-width: 0; padding: 1.3rem 1.5rem; border: 1px solid #e6d5b1; border-radius: .9rem; background: #fffaf0; box-shadow: 0 .3rem 1.2rem #735a2510; }
li span { max-width: 100%; color: #214d3a; font: 500 clamp(1.25rem, 2vw, 1.5rem)/1.3 Georgia, serif; overflow-wrap: anywhere; }
.con-imagenes li { padding: 1.5rem; }
img { display: block; width: min(10rem, 100%); aspect-ratio: 1; object-fit: cover; border: 3px solid #f8efdc; border-radius: 50%; box-shadow: 0 0 0 1px #d7bd85; }
</style>