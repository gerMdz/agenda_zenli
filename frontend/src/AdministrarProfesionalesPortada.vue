<script setup>
import { onMounted, ref } from 'vue'

const props = defineProps({ csrf: { type: String, required: true } })
const configuracion = ref(null)
const error = ref('')
const mensaje = ref('')
const ocupado = ref(false)

async function solicitar(ruta, opciones = {}) {
  ocupado.value = true
  error.value = ''
  mensaje.value = ''
  try {
    const respuesta = await fetch(ruta, opciones)
    const datos = await respuesta.json()
    if (!respuesta.ok) throw new Error(datos.error || 'No se pudo guardar la configuración.')
    configuracion.value = datos
    if (opciones.method) mensaje.value = 'Cambios guardados.'
  } catch (e) {
    error.value = e.message || 'No se pudo conectar.'
  } finally {
    ocupado.value = false
  }
}

function cambiarModo(evento) {
  solicitar('/api/admin/profesionales-portada/modo', {
    method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-Zenli-CSRF': props.csrf },
    body: JSON.stringify({ modo: evento.target.value }),
  })
}

function imagen(id, archivo, eliminar = false) {
  const datos = new FormData()
  if (archivo) datos.append('imagen', archivo)
  if (eliminar) datos.append('eliminar_imagen', '1')
  solicitar(`/api/admin/profesionales-portada/${id}/imagen`, {
    method: 'POST', headers: { 'X-Zenli-CSRF': props.csrf }, body: datos,
  })
}

function subir(id, evento) {
  const archivo = evento.target.files[0]
  if (archivo) imagen(id, archivo)
  evento.target.value = ''
}

onMounted(() => solicitar('/api/admin/profesionales-portada'))
</script>

<template>
  <section aria-labelledby="administrar-profesionales-titulo">
    <h2 id="administrar-profesionales-titulo">Profesionales en portada</h2>
    <p>Antes de publicar nombres o fotos, confirmá la autorización correspondiente. La configuración no registra consentimiento.</p>
    <p v-if="error" role="alert">{{ error }}</p>
    <p v-if="mensaje" role="status">{{ mensaje }}</p>
    <template v-if="configuracion">
      <label for="modo-profesionales">Presentación pública</label>
      <select id="modo-profesionales" :value="configuracion.modo" :disabled="ocupado" @change="cambiarModo">
        <option value="ocultos">Ocultos</option>
        <option value="nombres">Solo nombres</option>
        <option value="imagenes">Nombres e imágenes</option>
      </select>
      <p>Solo se muestran profesionales activas. Si falta alguna imagen, se muestran solo nombres hasta completar todas.</p>
      <ul>
        <li v-for="profesional in configuracion.profesionales" :key="profesional.id">
          <strong>{{ profesional.nombre }}</strong> <span v-if="!profesional.activa">(inactiva)</span>
          <img v-if="profesional.imagen_version" :src="`/api/admin/profesionales-portada/${profesional.id}/imagen/${profesional.imagen_version}`" :alt="`Imagen de ${profesional.nombre}`" width="100">
          <label>Subir imagen <input type="file" accept="image/jpeg,image/png,image/webp" :disabled="ocupado" @change="subir(profesional.id, $event)"></label>
          <button v-if="profesional.imagen_version" type="button" :disabled="ocupado" @click="imagen(profesional.id, null, true)">Eliminar imagen</button>
        </li>
      </ul>
    </template>
  </section>
</template>