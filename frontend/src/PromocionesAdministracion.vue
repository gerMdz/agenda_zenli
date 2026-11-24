<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { resumenAuditoria } from './formatoAuditoria.js'

const props = defineProps({ csrf: { type: String, required: true } })
const promociones = ref([])
const editando = ref(null)
const nombre = ref('')
const descripcion = ref('')
const archivo = ref(null)
const eliminarImagen = ref(false)
const versionFormulario = ref(0)
const abierto = ref(false)
const dialogo = ref(null)
const ocupado = ref(false)
const error = ref('')
const mensaje = ref('')
const imagenTemporal = ref(null)

async function solicitar(ruta, method = 'GET', datos) {
  const headers = { Accept: 'application/json' }
  if (method !== 'GET') headers['X-Zenli-CSRF'] = props.csrf
  if (datos && !(datos instanceof FormData)) headers['Content-Type'] = 'application/json'
  const respuesta = await fetch(ruta, { method, headers, body: datos instanceof FormData ? datos : datos ? JSON.stringify(datos) : undefined })
  const contenido = await respuesta.json().catch(() => ({}))
  if (!respuesta.ok) throw new Error(contenido.error || 'No se pudo completar la operación.')
  promociones.value = contenido.promociones
}

async function ejecutar(accion) {
  if (ocupado.value) return
  ocupado.value = true
  error.value = ''
  mensaje.value = ''
  try { await accion() } catch (fallo) { error.value = fallo.message } finally { ocupado.value = false }
}

function editar(promocion = null) {
  limpiarImagen()
  editando.value = promocion
  nombre.value = promocion?.nombre ?? ''
  descripcion.value = promocion?.descripcion ?? ''
  archivo.value = null
  eliminarImagen.value = false
  versionFormulario.value++
  error.value = ''
  mensaje.value = ''
  abierto.value = true
  nextTick(() => {
    dialogo.value.showModal()
    document.getElementById('promocion-nombre')?.focus()
  })
}

function limpiarImagen() {
  if (imagenTemporal.value) URL.revokeObjectURL(imagenTemporal.value)
  imagenTemporal.value = null
}

function cerrarFormulario() {
  if (dialogo.value?.open) dialogo.value.close()
  abierto.value = false
  archivo.value = null
  limpiarImagen()
  error.value = ''
}

function elegirImagen(evento) {
  const nueva = evento.target.files[0]
  if (!nueva) return
  if (nueva.size > 4 * 1024 * 1024) {
    limpiarImagen()
    archivo.value = null
    error.value = 'La imagen supera los 4 MB.'
    evento.target.value = ''
    return
  }
  limpiarImagen()
  archivo.value = nueva
  imagenTemporal.value = URL.createObjectURL(nueva)
  eliminarImagen.value = false
  error.value = ''
}

function guardar() {
  return ejecutar(async () => {
    const ruta = editando.value ? `/api/admin/promociones/${editando.value.id}` : '/api/admin/promociones'
    const datos = new FormData()
    datos.append('nombre', nombre.value)
    datos.append('descripcion', descripcion.value)
    if (archivo.value) datos.append('imagen', archivo.value)
    if (eliminarImagen.value) datos.append('eliminar_imagen', '1')
    const id = editando.value?.id
    await solicitar(ruta, 'POST', datos)
    if (id) {
      editando.value = promociones.value.find(promocion => promocion.id === id)
      archivo.value = null
      eliminarImagen.value = false
      versionFormulario.value++
      limpiarImagen()
      mensaje.value = 'Datos de la promoción guardados.'
    } else {
      cerrarFormulario()
      mensaje.value = 'Promoción creada.'
    }
  })
}

function eliminar(promocion) {
  if (window.confirm(`¿Eliminar la promoción «${promocion.nombre}»?`)) {
    ejecutar(() => solicitar(`/api/admin/promociones/${promocion.id}`, 'DELETE'))
  }
}

function moverPromocion(id, direccion) {
  return ejecutar(() => solicitar(`/api/admin/promociones/${id}/orden`, 'PATCH', { direccion }))
}

onMounted(() => ejecutar(() => solicitar('/api/admin/promociones')))
onBeforeUnmount(limpiarImagen)
</script>

<template>
  <section class="panel-promociones" aria-labelledby="titulo-promociones-admin">
    <div class="avisos"><p v-if="error && !abierto" class="error" role="alert">{{ error }}</p><p v-if="mensaje && !abierto" class="mensaje" role="status">{{ mensaje }}</p></div>
    <div class="cabecera"><h2 id="titulo-promociones-admin">Promociones de la portada</h2><button type="button" class="secundario" :disabled="ocupado" @click="editar()">+ Nueva promoción</button></div>
    <ul class="lista">
      <li v-for="(promocion, index) in promociones" :key="promocion.id" :class="{ oculto: !promocion.visible }">
        <div class="promocion-info"><span class="promocion-orden">{{ index + 1 }}</span><div class="promocion-detalle"><strong>{{ promocion.nombre }}</strong><small>{{ promocion.descripcion }}</small><small v-if="resumenAuditoria(promocion.auditoria, true)" class="meta-auditoria">{{ resumenAuditoria(promocion.auditoria, true) }}</small><span :class="['badge', { 'sin-imagen': !promocion.imagen_version }]">{{ promocion.imagen_version ? 'con imagen' : 'sin imagen' }}</span></div></div>
        <div class="promocion-acciones">
          <button type="button" class="secundario icono" :disabled="ocupado || index === 0" title="Mover arriba" :aria-label="`Mover ${promocion.nombre} arriba`" @click="moverPromocion(promocion.id, -1)">↑</button>
          <button type="button" class="secundario icono" :disabled="ocupado || index === promociones.length - 1" title="Mover abajo" :aria-label="`Mover ${promocion.nombre} abajo`" @click="moverPromocion(promocion.id, 1)">↓</button>
          <button type="button" class="secundario" :disabled="ocupado" @click="ejecutar(() => solicitar(`/api/admin/promociones/${promocion.id}/visibilidad`, 'PATCH', { visible: !promocion.visible }))">{{ promocion.visible ? 'Ocultar' : 'Mostrar' }}</button>
          <button type="button" class="secundario" :disabled="ocupado" @click="editar(promocion)">Editar</button>
          <button type="button" class="peligro" :disabled="ocupado" @click="eliminar(promocion)">Eliminar</button>
        </div>
      </li>
    </ul>
    <p v-if="!promociones.length" class="vacio">No hay promociones. Creá la primera.</p>
    <dialog ref="dialogo" aria-labelledby="titulo-promocion-dialogo" @close="cerrarFormulario" @cancel="ocupado && $event.preventDefault()">
    <div class="modal-cabecera">
      <div><span class="modal-etiqueta">PROMOCIONES DE ZENLI</span><h2 id="titulo-promocion-dialogo">{{ editando ? 'Editar promoción' : 'Nueva promoción' }}</h2><p v-if="editando">{{ editando.nombre }}</p><small v-if="editando && resumenAuditoria(editando.auditoria, true)" class="meta-auditoria">{{ resumenAuditoria(editando.auditoria, true) }}</small></div>
      <button type="button" class="cerrar-modal" aria-label="Cerrar formulario" :disabled="ocupado" @click="cerrarFormulario">✕</button>
    </div>
    <div class="modal-avisos" aria-live="polite"><p v-if="error" class="error inline" role="alert">{{ error }}</p><p v-if="mensaje" class="mensaje" role="status">{{ mensaje }}</p></div>
    <form class="formulario-promocion" @submit.prevent="guardar">
      <h3>Información para la portada</h3>
      <label for="promocion-nombre">Nombre</label>
      <input id="promocion-nombre" v-model="nombre" minlength="2" maxlength="100" :disabled="ocupado" required>
      <label for="promocion-descripcion">Descripción</label>
      <textarea id="promocion-descripcion" v-model="descripcion" minlength="5" maxlength="5000" rows="4" :disabled="ocupado" required></textarea>
      <label for="promocion-imagen">Imagen</label>
      <div v-if="editando?.imagen_version" class="imagen-actual"><img :src="`/api/admin/promociones/${editando.id}/imagen/${editando.imagen_version}`" alt="Imagen actual"></div>
      <div v-if="imagenTemporal" class="vista-previa-promocion"><img :src="imagenTemporal" alt="Vista previa nueva"></div>
      <input :key="versionFormulario" id="promocion-imagen" type="file" accept="image/jpeg,image/png,image/webp" :disabled="ocupado || eliminarImagen" @change="elegirImagen">
      <label v-if="editando?.imagen_version" class="casilla"><input v-model="eliminarImagen" type="checkbox" :disabled="ocupado || !!archivo"> Eliminar imagen actual</label>
      <small v-if="editando">Subí una nueva imagen para reemplazar la actual o marcá para eliminarla. JPG, PNG o WebP, hasta 4 MB y 6000 píxeles por lado.</small>
      <template v-else><small>JPG, PNG o WebP, hasta 4 MB y 6000 píxeles por lado. La imagen es opcional.</small><small>Las promociones nuevas se crean ocultas.</small></template>
      <div class="acciones"><button type="submit" :disabled="ocupado" class="boton-formulario boton-formulario--guardar">{{ editando ? 'Guardar cambios' : 'Crear promoción' }}</button></div>
    </form>
    <div class="modal-pie"><button type="button" class="boton-formulario boton-formulario--cancelar" :disabled="ocupado" @click="cerrarFormulario">Cerrar</button></div>
    </dialog>
  </section>
</template>

<style scoped>
.panel-promociones { background: #fffaf1; padding: clamp(1rem, 3vw, 1.6rem); border: 1px solid #e4dac3; border-radius: .9rem; }
.cabecera { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
h2 { font-family: Georgia, serif; margin: 0 0 .4rem; }
h3 { margin: 0 0 .4rem; font-size: 1rem; overflow-wrap: anywhere; }
.acciones { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .5rem; }
.lista { margin: .75rem 0 0; padding: 0; list-style: none; }
.lista li { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .6rem 0; border-bottom: 1px solid #e4dac3; font-size: .88rem; }
.lista li.oculto { opacity: .5; }
.promocion-info { display: flex; align-items: center; gap: .6rem; min-width: 0; }
.promocion-orden { width: 1.6rem; height: 1.6rem; display: flex; align-items: center; justify-content: center; background: #214d3a; color: #fffdfa; border-radius: 50%; font-size: .75rem; font-weight: 700; flex-shrink: 0; }
.promocion-detalle { min-width: 0; overflow-wrap: anywhere; }
.promocion-detalle strong, .promocion-detalle small { display: block; }
.promocion-detalle small { white-space: pre-wrap; }
.promocion-acciones { display: flex; align-items: center; gap: .35rem; flex-shrink: 0; }
.promocion-acciones button.icono { padding: .3rem .45rem; font-size: .9rem; }
.badge { display: inline-block; font-size: .7rem; padding: .1rem .4rem; border-radius: .3rem; background: #e4efdf; color: #194832; margin-left: .3rem; }
.badge.sin-imagen { background: #f9e3df; color: #75271f; }
.vacio { color: #526255; font-style: italic; margin: 1rem 0 0; }
small { font-size: .79rem; color: #526255; }
dialog { width: min(42rem, calc(100% - 2rem)); max-height: min(90svh, 60rem); box-sizing: border-box; padding: clamp(1.2rem, 4vw, 2rem); border: 1px solid #e4dac3; border-radius: 1.1rem; background: #fffaf1; color: #15372a; box-shadow: 0 1.5rem 5rem #08271c55; }
dialog::backdrop { background: #08271cb8; }
.modal-cabecera { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; padding-bottom: 1rem; border-bottom: 1px solid #e4dac3; }
.modal-cabecera h2 { font-size: clamp(1.6rem, 4vw, 2.1rem); margin: .25rem 0; }
.modal-cabecera p { margin: 0; color: #526255; overflow-wrap: anywhere; }
.modal-etiqueta { color: #8f631e; font-size: .7rem; font-weight: 750; letter-spacing: .12em; }
form { display: grid; gap: .55rem; margin-top: 1.25rem; }
dialog form { margin-top: 1.4rem; }
label { font-weight: 650; font-size: .88rem; }
input, textarea { box-sizing: border-box; min-width: 0; width: 100%; padding: .62rem .7rem; border: 1px solid #aaab98; border-radius: .4rem; background: #fffdf7; color: #15372a; font: inherit; }
textarea { resize: vertical; }
input[type=checkbox] { width: auto; }
.casilla { display: flex; align-items: center; gap: .4rem; }
.vista-previa-promocion, .imagen-actual { margin: .5rem 0; }
.vista-previa-promocion img, .imagen-actual img { max-width: min(100%, 20rem); height: auto; border-radius: .5rem; border: 1px solid #e4dac3; }
button { width: fit-content; padding: .56rem .85rem; border: 1px solid #214d3a; border-radius: .4rem; background: #214d3a; color: #fffdfa; cursor: pointer; font: inherit; font-weight: 650; font-size: .89rem; }
button.secundario { background: #fff9ec; color: #214d3a; }
button.peligro { background: #75271f; border-color: #75271f; }
button.cerrar-modal { flex-shrink: 0; border: 1px solid #e4dac3; border-radius: 50%; width: 2.5rem; height: 2.5rem; padding: 0; background: #fffdf7; color: #214d3a; }
button:disabled { opacity: .54; cursor: default; }
button:focus-visible, input:focus-visible, textarea:focus-visible { outline: 2px solid #8f631e; outline-offset: 3px; }
.error, .mensaje { border-radius: .4rem; padding: .65rem .8rem; }
.error { background: #f9e3df; color: #75271f; }
.error.inline { margin: 0 0 .5rem; }
.mensaje { background: #e4efdf; color: #194832; }
.avisos, .modal-avisos { display: contents; }
.avisos p { position: sticky; top: .5rem; z-index: 1; margin: 0 0 .75rem; box-shadow: 0 .25rem 1rem #15372a1a; }
.modal-avisos p { position: sticky; top: 0; z-index: 1; margin: .6rem 0; box-shadow: 0 .25rem 1rem #15372a1a; }
.modal-pie { display: flex; justify-content: flex-end; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #e4dac3; }
@media (max-width: 46rem) {
  .lista li { flex-direction: column; align-items: flex-start; }
  .promocion-acciones { width: 100%; justify-content: flex-start; flex-wrap: wrap; }
}
</style>