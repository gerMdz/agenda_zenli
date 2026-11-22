<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { resumenAuditoria } from './formatoAuditoria.js'

const props = defineProps({ csrf: { type: String, required: true } })
const emit = defineEmits(['nombre-actualizado'])
const configuracion = ref(null)
const error = ref('')
const mensaje = ref('')
const ocupado = ref(false)
const dialogo = ref(null)
const entradaImagen = ref(null)
const profesionalEditando = ref(null)
const nombreEditado = ref('')
const archivoEditado = ref(null)
const imagenTemporal = ref(null)
const eliminarImagen = ref(false)
const errorEdicion = ref('')
const mensajeEdicion = ref('')

async function solicitar(ruta, opciones = {}, enModal = false) {
  if (ocupado.value) return false
  ocupado.value = true
  if (enModal) {
    errorEdicion.value = ''
    mensajeEdicion.value = ''
  } else {
    error.value = ''
    mensaje.value = ''
  }
  try {
    const respuesta = await fetch(ruta, opciones)
    const datos = respuesta.headers.get('content-type')?.includes('application/json') ? await respuesta.json() : null
    if (!respuesta.ok) throw new Error(datos?.error || (respuesta.status === 413 ? 'La imagen supera los 4 MB.' : 'No se pudo guardar la configuración.'))
    configuracion.value = datos
    if (opciones.method) {
      if (enModal) mensajeEdicion.value = 'Imagen guardada.'
      else mensaje.value = 'Cambios guardados.'
    }
    return true
  } catch (e) {
    if (enModal) errorEdicion.value = e.message || 'No se pudo conectar.'
    else error.value = e.message || 'No se pudo conectar.'
    return false
  } finally {
    ocupado.value = false
  }
}

async function cambiarModo(evento) {
  const selector = evento.target
  if (!await solicitar('/api/admin/profesionales-portada/modo', {
    method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-Zenli-CSRF': props.csrf },
    body: JSON.stringify({ modo: selector.value }),
  })) selector.value = configuracion.value.modo
}

function cambiarVisibilidad(profesional) {
  solicitar(`/api/admin/profesionales-portada/${profesional.id}/visibilidad`, {
    method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-Zenli-CSRF': props.csrf },
    body: JSON.stringify({ visible: !profesional.visible_portada }),
  })
}

function mover(id, direccion) {
  solicitar(`/api/admin/profesionales-portada/${id}/orden`, {
    method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-Zenli-CSRF': props.csrf },
    body: JSON.stringify({ direccion }),
  })
}

async function guardarImagen() {
  if (!archivoEditado.value && !eliminarImagen.value) return
  const datos = new FormData()
  if (eliminarImagen.value) datos.append('eliminar_imagen', '1')
  else if (archivoEditado.value) datos.append('imagen', archivoEditado.value)
  const id = profesionalEditando.value.id
  if (await solicitar(`/api/admin/profesionales-portada/${id}/imagen`, {
    method: 'POST', headers: { 'X-Zenli-CSRF': props.csrf }, body: datos,
  }, true)) {
    profesionalEditando.value = configuracion.value.profesionales.find(profesional => profesional.id === id)
    limpiarImagen()
    if (entradaImagen.value) entradaImagen.value.value = ''
    eliminarImagen.value = false
  }
}

async function guardarNombre() {
  const id = profesionalEditando.value.id
  if (await solicitar(`/api/admin/profesionales-portada/${id}/nombre`, {
    method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-Zenli-CSRF': props.csrf },
    body: JSON.stringify({ nombre: nombreEditado.value }),
  }, true)) {
    profesionalEditando.value = configuracion.value.profesionales.find(profesional => profesional.id === id)
    nombreEditado.value = profesionalEditando.value.nombre
    mensajeEdicion.value = 'Nombre guardado.'
    emit('nombre-actualizado')
  }
}

function limpiarImagen() {
  if (imagenTemporal.value) URL.revokeObjectURL(imagenTemporal.value)
  imagenTemporal.value = null
  archivoEditado.value = null
}

function elegirImagen(evento) {
  const archivo = evento.target.files[0]
  if (!archivo) return
  if (archivo.size > 4 * 1024 * 1024) {
    errorEdicion.value = 'La imagen supera los 4 MB.'
    evento.target.value = ''
    return
  }
  limpiarImagen()
  errorEdicion.value = ''
  eliminarImagen.value = false
  archivoEditado.value = archivo
  imagenTemporal.value = URL.createObjectURL(archivo)
}

async function abrirEdicion(profesional) {
  error.value = ''
  mensaje.value = ''
  profesionalEditando.value = profesional
  nombreEditado.value = profesional.nombre
  await nextTick()
  dialogo.value.showModal()
}

function cerrarEdicion() {
  if (dialogo.value?.open) dialogo.value.close()
  profesionalEditando.value = null
  nombreEditado.value = ''
  limpiarImagen()
  eliminarImagen.value = false
  errorEdicion.value = ''
  mensajeEdicion.value = ''
}

onMounted(() => solicitar('/api/admin/profesionales-portada'))
onBeforeUnmount(limpiarImagen)
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
        <small id="ayuda-modo-profesionales">Solo se muestran profesionales activas y visibles. El modo global prevalece sobre la visibilidad individual. Si falta alguna imagen, se muestran solo nombres hasta completar todas.</small>
        <small v-if="resumenAuditoria(configuracion.auditoria, true)">Configuración: {{ resumenAuditoria(configuracion.auditoria, true) }}</small>
      </div>
      <ul class="lista-servicios">
        <li v-for="(profesional, index) in configuracion.profesionales" :key="profesional.id" :class="{ oculto: !profesional.activa || !profesional.visible_portada }">
          <div class="servicio-info">
            <span class="servicio-orden">{{ index + 1 }}</span>
            <div class="servicio-detalle">
              <strong>{{ profesional.nombre }}</strong>
              <small v-if="!profesional.activa">Inactiva</small>
              <small>{{ profesional.visible_portada ? 'Visible en portada' : 'Oculta en portada' }}</small>
              <small v-if="resumenAuditoria(profesional.auditoria, true)">Perfil: {{ resumenAuditoria(profesional.auditoria, true) }}</small>
              <small v-if="resumenAuditoria(profesional.imagen_auditoria, true)">Imagen: {{ resumenAuditoria(profesional.imagen_auditoria, true) }}</small>
              <span class="badge" :class="{ 'sin-imagen': !profesional.imagen_version }">{{ profesional.imagen_version ? 'con imagen' : 'sin imagen' }}</span>
            </div>
          </div>
          <div class="servicio-acciones">
            <button type="button" class="secundario" :disabled="ocupado" :aria-label="`${profesional.visible_portada ? 'Ocultar' : 'Mostrar'} a ${profesional.nombre} en portada`" @click="cambiarVisibilidad(profesional)">{{ profesional.visible_portada ? 'Ocultar' : 'Mostrar' }}</button>
            <button type="button" class="secundario icono" :disabled="ocupado || index === 0" :aria-label="`Subir a ${profesional.nombre}`" title="Mover arriba" @click="mover(profesional.id, -1)">↑</button>
            <button type="button" class="secundario icono" :disabled="ocupado || index === configuracion.profesionales.length - 1" :aria-label="`Bajar a ${profesional.nombre}`" title="Mover abajo" @click="mover(profesional.id, 1)">↓</button>
            <button type="button" class="secundario" :disabled="ocupado" :aria-label="`Editar ${profesional.nombre}`" @click="abrirEdicion(profesional)">Editar</button>
          </div>
        </li>
      </ul>
      <p v-if="!configuracion.profesionales.length" class="vacío">Todavía no hay profesionales cargadas.</p>
      <dialog ref="dialogo" class="modal-servicio" aria-labelledby="titulo-editar-profesional-portada" @close="cerrarEdicion" @cancel="ocupado && $event.preventDefault()">
        <template v-if="profesionalEditando">
          <div class="modal-cabecera">
            <div><span class="modal-etiqueta">EQUIPO DE ZENLI</span><h2 id="titulo-editar-profesional-portada">Editar profesional</h2><p>{{ profesionalEditando.nombre }}</p></div>
            <button type="button" class="cerrar-modal" aria-label="Cerrar edición" :disabled="ocupado" @click="cerrarEdicion">✕</button>
          </div>
          <div class="modal-avisos" aria-live="polite">
            <p v-if="errorEdicion" class="error" role="alert">{{ errorEdicion }}</p>
            <p v-if="mensajeEdicion" class="mensaje" role="status">{{ mensajeEdicion }}</p>
          </div>
          <form class="formulario-servicio" @submit.prevent="guardarNombre">
            <label for="nombre-profesional-portada">Nombre</label>
            <input id="nombre-profesional-portada" v-model.trim="nombreEditado" maxlength="120" required :disabled="ocupado" aria-describedby="ayuda-nombre-profesional">
            <small id="ayuda-nombre-profesional">Este nombre también se usa en la agenda y en el perfil profesional.</small>
            <div class="acciones"><button :disabled="ocupado || !nombreEditado || nombreEditado === profesionalEditando.nombre">Guardar nombre</button></div>
          </form>
          <form class="formulario-servicio" :key="profesionalEditando.id" @submit.prevent="guardarImagen">
            <h3>Información para la portada</h3>
            <label for="imagen-profesional">Imagen</label>
            <div v-if="profesionalEditando.imagen_version" class="imagen-actual">
              <img :src="`/api/admin/profesionales-portada/${profesionalEditando.id}/imagen/${profesionalEditando.imagen_version}`" alt="Imagen actual">
            </div>
            <div v-if="imagenTemporal" class="vista-previa-servicio"><img :src="imagenTemporal" alt="Vista previa nueva"></div>
            <input id="imagen-profesional" ref="entradaImagen" type="file" accept="image/jpeg,image/png,image/webp" :disabled="ocupado" aria-describedby="ayuda-imagen-profesional" @change="elegirImagen">
            <label v-if="profesionalEditando.imagen_version" class="casilla"><input v-model="eliminarImagen" type="checkbox" :disabled="ocupado"> Eliminar imagen actual</label>
            <small id="ayuda-imagen-profesional">JPG, PNG o WebP, hasta 4 MB de peso. Subí una nueva imagen para reemplazar la actual o marcá para eliminarla. Si marcás eliminar, no se guardará la imagen nueva.</small>
            <div class="acciones"><button :disabled="ocupado || (!archivoEditado && !eliminarImagen)">Guardar imagen</button></div>
          </form>
          <div class="modal-pie"><button type="button" class="secundario" :disabled="ocupado" @click="cerrarEdicion">Cerrar</button></div>
        </template>
      </dialog>
    </template>
  </section>
</template>

<style scoped>
h2, h3 { margin: 0 0 .4rem; }
h2 { font-family: Georgia, serif; }
h3 { font-size: 1rem; overflow-wrap: anywhere; }
p { overflow-wrap: anywhere; }
label { font-weight: 650; font-size: .88rem; }
small { font-size: .79rem; color: #526255; }
select, input:not([type='checkbox']) { box-sizing: border-box; min-width: 0; width: 100%; padding: .62rem .7rem; border: 1px solid #aaab98; border-radius: .4rem; background: #fffdf7; color: #15372a; font: inherit; }
button { width: fit-content; padding: .56rem .85rem; border: 1px solid #214d3a; border-radius: .4rem; background: #214d3a; color: #fffdfa; cursor: pointer; font: inherit; font-size: .89rem; font-weight: 650; }
button.secundario { background: #fff9ec; color: #214d3a; }
button:disabled, input:disabled, select:disabled { opacity: .54; cursor: default; }
button:focus-visible, input:focus-visible, select:focus-visible { outline: 2px solid #8f631e; outline-offset: 3px; }
.avisos { display: contents; }
.avisos p { position: sticky; top: .5rem; z-index: 1; margin: 0 0 .75rem; padding: .65rem .8rem; border-radius: .4rem; box-shadow: 0 .25rem 1rem #15372a1a; }
.error { background: #f9e3df; color: #75271f; }
.mensaje { background: #e4efdf; color: #194832; }
.presentacion { display: grid; gap: .55rem; }
.presentacion { margin-top: 1.25rem; }
.lista-servicios { margin: .75rem 0 0; padding: 0; list-style: none; }
.lista-servicios li { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .6rem 0; border-bottom: 1px solid #e4dac3; font-size: .88rem; }
.lista-servicios li.oculto { opacity: .5; }
.servicio-info { display: flex; align-items: center; gap: .6rem; min-width: 0; }
.servicio-orden { width: 1.6rem; height: 1.6rem; display: flex; align-items: center; justify-content: center; background: #214d3a; color: #fffdfa; border-radius: 50%; font-size: .75rem; font-weight: 700; flex-shrink: 0; }
.servicio-detalle { min-width: 0; overflow-wrap: anywhere; }
.servicio-detalle strong, .servicio-detalle small { display: block; }
.servicio-acciones { display: flex; align-items: center; gap: .35rem; flex-shrink: 0; }
.servicio-acciones button.icono { padding: .3rem .45rem; font-size: .9rem; }
.badge { display: inline-block; margin-left: .3rem; padding: .1rem .4rem; border-radius: .3rem; background: #e4efdf; color: #194832; font-size: .7rem; }
.badge.sin-imagen { background: #f9e3df; color: #75271f; }
.modal-servicio { width: min(42rem, calc(100% - 2rem)); max-height: min(90svh, 60rem); padding: clamp(1.2rem, 4vw, 2rem); border: 1px solid #e4dac3; border-radius: 1.1rem; background: #fffaf1; color: #15372a; box-shadow: 0 1.5rem 5rem #08271c55; }
.modal-servicio::backdrop { background: #08271cb8; }
.modal-cabecera { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; padding-bottom: 1rem; border-bottom: 1px solid #e4dac3; }
.modal-cabecera h2 { font-size: clamp(1.6rem, 4vw, 2.1rem); margin: .25rem 0; }
.modal-cabecera p { margin: 0; color: #526255; }
.modal-etiqueta { color: #8f631e; font-size: .7rem; font-weight: 750; letter-spacing: .12em; }
button.cerrar-modal { flex-shrink: 0; border: 1px solid #e4dac3; border-radius: 50%; width: 2.5rem; height: 2.5rem; padding: 0; background: #fffdf7; color: #214d3a; }
.modal-avisos { display: contents; }
.modal-avisos p { position: sticky; top: 0; z-index: 1; margin: .6rem 0; padding: .65rem .8rem; border-radius: .4rem; box-shadow: 0 .25rem 1rem #15372a1a; }
.formulario-servicio { display: grid; gap: .55rem; margin-top: 1.4rem; }
.casilla { display: flex; align-items: center; gap: .4rem; }
.acciones { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .5rem; }
.modal-pie { display: flex; justify-content: flex-end; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #e4dac3; }
.vista-previa-servicio, .imagen-actual { margin: .5rem 0; }
.vista-previa-servicio img, .imagen-actual img { max-width: min(100%, 20rem); height: auto; border-radius: .5rem; border: 1px solid #e4dac3; }
.vacío { color: #526255; font-style: italic; margin: 1rem 0 0; }
@media (max-width: 46rem) {
  .lista-servicios li { flex-direction: column; align-items: flex-start; }
  .servicio-acciones { width: 100%; justify-content: flex-start; }
}
</style>