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
    <div class="avisos">
      <p v-if="error" class="error" role="alert">{{ error }}</p>
      <p v-if="mensaje" class="mensaje" role="status">{{ mensaje }}</p>
    </div>
    <template v-if="configuracion">
      <div class="presentacion">
        <label for="modo-profesionales">Presentación pública</label>
        <select id="modo-profesionales" :value="configuracion.modo" :disabled="ocupado" aria-describedby="ayuda-modo-profesionales" @change="cambiarModo">
          <option value="ocultos">Ocultos</option>
          <option value="nombres">Solo nombres</option>
          <option value="imagenes">Nombres e imágenes</option>
        </select>
        <small id="ayuda-modo-profesionales">Solo se muestran profesionales activas. Si falta alguna imagen, se muestran solo nombres hasta completar todas.</small>
      </div>
      <ul class="lista-profesionales">
        <li v-for="profesional in configuracion.profesionales" :key="profesional.id">
          <div class="profesional-detalle">
            <strong>{{ profesional.nombre }} <span v-if="!profesional.activa">(inactiva)</span></strong>
            <span class="badge" :class="{ 'sin-imagen': !profesional.imagen_version }">{{ profesional.imagen_version ? 'con imagen' : 'sin imagen' }}</span>
          </div>
          <div class="imagen-profesional">
            <img v-if="profesional.imagen_version" :src="`/api/admin/profesionales-portada/${profesional.id}/imagen/${profesional.imagen_version}`" :alt="`Imagen de ${profesional.nombre}`">
            <div class="imagen-controles">
              <label :for="`imagen-profesional-${profesional.id}`">Subir imagen de {{ profesional.nombre }}</label>
              <input :id="`imagen-profesional-${profesional.id}`" type="file" accept="image/jpeg,image/png,image/webp" :disabled="ocupado" aria-describedby="ayuda-imagen-profesional" @change="subir(profesional.id, $event)">
              <button v-if="profesional.imagen_version" type="button" class="secundario" :disabled="ocupado" :aria-label="`Eliminar imagen de ${profesional.nombre}`" @click="imagen(profesional.id, null, true)">Eliminar imagen</button>
            </div>
          </div>
        </li>
      </ul>
      <small id="ayuda-imagen-profesional">JPG, PNG o WebP, hasta 4 MB de peso. Subí una nueva imagen para reemplazar la actual.</small>
    </template>
  </section>
</template>

<style scoped>
h2 { margin: 0 0 .4rem; font-family: Georgia, serif; }
p { overflow-wrap: anywhere; }
label { font-weight: 650; font-size: .88rem; }
small { font-size: .79rem; color: #526255; }
select, input { box-sizing: border-box; min-width: 0; width: 100%; padding: .62rem .7rem; border: 1px solid #aaab98; border-radius: .4rem; background: #fffdf7; color: #15372a; font: inherit; }
button { width: fit-content; padding: .56rem .85rem; border: 1px solid #214d3a; border-radius: .4rem; background: #fff9ec; color: #214d3a; cursor: pointer; font: inherit; font-size: .89rem; font-weight: 650; }
button:disabled, input:disabled, select:disabled { opacity: .54; cursor: default; }
button:focus-visible, input:focus-visible, select:focus-visible { outline: 2px solid #8f631e; outline-offset: 3px; }
.avisos { display: contents; }
.avisos p { position: sticky; top: .5rem; z-index: 1; margin: 0 0 .75rem; padding: .65rem .8rem; border-radius: .4rem; box-shadow: 0 .25rem 1rem #15372a1a; }
.error { background: #f9e3df; color: #75271f; }
.mensaje { background: #e4efdf; color: #194832; }
.presentacion, .imagen-controles { display: grid; gap: .55rem; }
.presentacion { margin-top: 1.25rem; }
.lista-profesionales { margin: .75rem 0; padding: 0; list-style: none; }
.lista-profesionales li { padding: .8rem 0; border-bottom: 1px solid #e4dac3; font-size: .88rem; }
.profesional-detalle { min-width: 0; overflow-wrap: anywhere; }
.badge { display: inline-block; margin-left: .3rem; padding: .1rem .4rem; border-radius: .3rem; background: #e4efdf; color: #194832; font-size: .7rem; }
.badge.sin-imagen { background: #f9e3df; color: #75271f; }
.imagen-profesional { display: flex; align-items: flex-start; gap: 1rem; margin-top: .6rem; }
.imagen-profesional img { width: 100px; max-width: 100%; height: auto; border: 1px solid #e4dac3; border-radius: .5rem; }
.imagen-controles { flex: 1; min-width: 0; }
@media (max-width: 46rem) {
  .imagen-profesional { flex-direction: column; }
  .imagen-controles { width: 100%; }
}
</style>