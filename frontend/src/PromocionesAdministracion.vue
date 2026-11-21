<script setup>
import { onMounted, ref } from 'vue'

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
  try { await accion() } catch (fallo) { error.value = fallo.message } finally { ocupado.value = false }
}

function editar(promocion = null) {
  editando.value = promocion
  nombre.value = promocion?.nombre ?? ''
  descripcion.value = promocion?.descripcion ?? ''
  archivo.value = null
  eliminarImagen.value = false
  versionFormulario.value++
  error.value = ''
  abierto.value = true
  dialogo.value.showModal()
}

function guardar() {
  return ejecutar(async () => {
    const ruta = editando.value ? `/api/admin/promociones/${editando.value.id}` : '/api/admin/promociones'
    const datos = new FormData()
    datos.append('nombre', nombre.value)
    datos.append('descripcion', descripcion.value)
    if (archivo.value) datos.append('imagen', archivo.value)
    if (eliminarImagen.value) datos.append('eliminar_imagen', '1')
    await solicitar(ruta, 'POST', datos)
    dialogo.value.close()
  })
}

function eliminar(promocion) {
  if (window.confirm(`¿Eliminar la promoción «${promocion.nombre}»?`)) {
    ejecutar(() => solicitar(`/api/admin/promociones/${promocion.id}`, 'DELETE'))
  }
}

onMounted(() => ejecutar(() => solicitar('/api/admin/promociones')))
</script>

<template>
  <section class="panel-promociones" aria-labelledby="titulo-promociones-admin">
    <div class="cabecera"><h2 id="titulo-promociones-admin">Promociones de la portada</h2><button type="button" class="secundario" :disabled="ocupado" @click="editar()">+ Nueva promoción</button></div>
    <p v-if="error && !abierto" class="error" role="alert">{{ error }}</p>
    <ul class="lista">
      <li v-for="promocion in promociones" :key="promocion.id" :class="{ oculto: !promocion.visible }">
        <div><strong>{{ promocion.nombre }}</strong><p>{{ promocion.descripcion }}</p><small>{{ promocion.visible ? 'Visible' : 'Oculta' }}</small></div>
        <div class="acciones">
          <button type="button" class="secundario" :disabled="ocupado" @click="ejecutar(() => solicitar(`/api/admin/promociones/${promocion.id}/visibilidad`, 'PATCH', { visible: !promocion.visible }))">{{ promocion.visible ? 'Ocultar' : 'Mostrar' }}</button>
          <button type="button" class="secundario" :disabled="ocupado" @click="editar(promocion)">Editar</button>
          <button type="button" class="peligro" :disabled="ocupado" @click="eliminar(promocion)">Eliminar</button>
        </div>
      </li>
    </ul>
    <p v-if="!promociones.length">No hay promociones cargadas.</p>
    <dialog ref="dialogo" aria-labelledby="titulo-promocion-dialogo" @close="abierto = false" @cancel="ocupado && $event.preventDefault()">
    <div class="modal-cabecera">
      <div><span class="modal-etiqueta">PROMOCIONES DE ZENLI</span><h2 id="titulo-promocion-dialogo">{{ editando ? 'Editar promoción' : 'Nueva promoción' }}</h2><p v-if="editando">{{ editando.nombre }}</p></div>
      <button type="button" class="cerrar-modal" aria-label="Cerrar" :disabled="ocupado" @click="dialogo.close()">✕</button>
    </div>
    <form @submit.prevent="guardar">
      <p v-if="error" class="error" role="alert">{{ error }}</p>
      <label for="promocion-nombre">Nombre</label>
      <input id="promocion-nombre" v-model="nombre" minlength="2" maxlength="100" :disabled="ocupado" required>
      <label for="promocion-descripcion">Descripción</label>
      <textarea id="promocion-descripcion" v-model="descripcion" minlength="5" maxlength="5000" rows="4" :disabled="ocupado" required></textarea>
      <img v-if="editando?.imagen_version && !eliminarImagen" class="imagen-promocion" :src="`/api/admin/promociones/${editando.id}/imagen/${editando.imagen_version}`" alt="Imagen actual de la promoción">
      <label for="promocion-imagen">Imagen opcional</label>
      <input :key="versionFormulario" id="promocion-imagen" type="file" accept="image/jpeg,image/png,image/webp" :disabled="ocupado || eliminarImagen" @change="archivo = $event.target.files[0] ?? null">
      <small>JPG, PNG o WebP, hasta 4 MB y 6000 píxeles por lado.</small>
      <label v-if="editando?.imagen_version"><input v-model="eliminarImagen" type="checkbox" :disabled="ocupado || !!archivo"> Eliminar imagen actual</label>
      <small>Las promociones nuevas se crean ocultas.</small>
      <div class="acciones"><button :disabled="ocupado">Guardar</button><button type="button" class="secundario" :disabled="ocupado" @click="dialogo.close()">Cancelar</button></div>
    </form>
    </dialog>
  </section>
</template>

<style scoped>
.panel-promociones { background: #fffaf1; padding: clamp(1rem, 3vw, 1.6rem); border: 1px solid #e4dac3; border-radius: .9rem; }
.cabecera { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
h2 { font-family: Georgia, serif; margin: 0 0 .4rem; }
.acciones { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem; }
.lista { margin: .75rem 0 0; padding: 0; list-style: none; }
.lista li { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .6rem 0; border-bottom: 1px solid #e4dac3; font-size: .88rem; }
.lista li.oculto { opacity: .5; }
.lista li > div:first-child { min-width: 0; }
.lista p { margin: .2rem 0; white-space: pre-wrap; overflow-wrap: anywhere; }
small { font-size: .79rem; color: #526255; }
dialog { width: min(42rem, calc(100% - 2rem)); max-height: min(90svh, 60rem); box-sizing: border-box; padding: clamp(1.2rem, 4vw, 2rem); border: 1px solid #e4dac3; border-radius: 1.1rem; background: #fffaf1; color: #15372a; box-shadow: 0 1.5rem 5rem #08271c55; }
dialog::backdrop { background: #08271cb8; }
.modal-cabecera { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; padding-bottom: 1rem; border-bottom: 1px solid #e4dac3; }
.modal-cabecera h2 { font-size: clamp(1.6rem, 4vw, 2.1rem); margin: .25rem 0; }
.modal-cabecera p { margin: 0; color: #526255; overflow-wrap: anywhere; }
.modal-etiqueta { color: #8f631e; font-size: .7rem; font-weight: 750; letter-spacing: .12em; }
form { display: grid; gap: .55rem; margin-top: 1.4rem; }
label { font-weight: 650; font-size: .88rem; }
input, textarea { box-sizing: border-box; min-width: 0; width: 100%; padding: .62rem .7rem; border: 1px solid #aaab98; border-radius: .4rem; background: #fffdf7; color: #15372a; font: inherit; }
textarea { resize: vertical; }
input[type=checkbox] { width: auto; }
.imagen-promocion { max-width: 100%; max-height: 12rem; object-fit: contain; }
button { width: fit-content; padding: .56rem .85rem; border: 1px solid #214d3a; border-radius: .4rem; background: #214d3a; color: #fffdfa; cursor: pointer; font: inherit; font-weight: 650; font-size: .89rem; }
button.secundario { background: #fff9ec; color: #214d3a; }
button.peligro { background: #75271f; border-color: #75271f; }
button.cerrar-modal { flex-shrink: 0; border: 1px solid #e4dac3; border-radius: 50%; width: 2.5rem; height: 2.5rem; padding: 0; background: #fffdf7; color: #214d3a; }
button:disabled { opacity: .54; cursor: default; }
button:focus-visible, input:focus-visible, textarea:focus-visible { outline: 2px solid #8f631e; outline-offset: 3px; }
.error { border-radius: .4rem; padding: .65rem .8rem; background: #f9e3df; color: #75271f; }
@media (max-width: 46rem) {
  .lista li { flex-direction: column; align-items: flex-start; }
}
</style>