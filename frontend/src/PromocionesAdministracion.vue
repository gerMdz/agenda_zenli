<script setup>
import { onMounted, ref } from 'vue'

const props = defineProps({ csrf: { type: String, required: true } })
const promociones = ref([])
const editando = ref(null)
const nombre = ref('')
const descripcion = ref('')
const abierto = ref(false)
const dialogo = ref(null)
const ocupado = ref(false)
const error = ref('')

async function solicitar(ruta, method = 'GET', datos) {
  const headers = { Accept: 'application/json' }
  if (method !== 'GET') headers['X-Zenli-CSRF'] = props.csrf
  if (datos) headers['Content-Type'] = 'application/json'
  const respuesta = await fetch(ruta, { method, headers, body: datos ? JSON.stringify(datos) : undefined })
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
  error.value = ''
  abierto.value = true
  dialogo.value.showModal()
}

function guardar() {
  return ejecutar(async () => {
    const ruta = editando.value ? `/api/admin/promociones/${editando.value.id}` : '/api/admin/promociones'
    await solicitar(ruta, editando.value ? 'PUT' : 'POST', { nombre: nombre.value, descripcion: descripcion.value })
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
    <div class="cabecera"><h2 id="titulo-promociones-admin">Promociones de la portada</h2><button type="button" :disabled="ocupado" @click="editar()">+ Nueva promoción</button></div>
    <p v-if="error && !abierto" class="error" role="alert">{{ error }}</p>
    <ul class="lista">
      <li v-for="promocion in promociones" :key="promocion.id">
        <div><strong>{{ promocion.nombre }}</strong><p>{{ promocion.descripcion }}</p><small>{{ promocion.visible ? 'Visible' : 'Oculta' }}</small></div>
        <div class="acciones">
          <button type="button" :disabled="ocupado" @click="ejecutar(() => solicitar(`/api/admin/promociones/${promocion.id}/visibilidad`, 'PATCH', { visible: !promocion.visible }))">{{ promocion.visible ? 'Ocultar' : 'Mostrar' }}</button>
          <button type="button" :disabled="ocupado" @click="editar(promocion)">Editar</button>
          <button type="button" :disabled="ocupado" @click="eliminar(promocion)">Eliminar</button>
        </div>
      </li>
    </ul>
    <p v-if="!promociones.length">No hay promociones cargadas.</p>
    <dialog ref="dialogo" aria-labelledby="titulo-promocion-dialogo" @close="abierto = false" @cancel="ocupado && $event.preventDefault()">
    <form @submit.prevent="guardar">
      <h3 id="titulo-promocion-dialogo">{{ editando ? 'Editar promoción' : 'Nueva promoción' }}</h3>
      <p v-if="error" class="error" role="alert">{{ error }}</p>
      <label for="promocion-nombre">Nombre</label>
      <input id="promocion-nombre" v-model="nombre" minlength="2" maxlength="100" :disabled="ocupado" required>
      <label for="promocion-descripcion">Descripción</label>
      <textarea id="promocion-descripcion" v-model="descripcion" minlength="5" maxlength="5000" rows="4" :disabled="ocupado" required></textarea>
      <small>Las promociones nuevas se crean ocultas.</small>
      <div class="acciones"><button :disabled="ocupado">Guardar</button><button type="button" :disabled="ocupado" @click="dialogo.close()">Cancelar</button></div>
    </form>
    </dialog>
  </section>
</template>

<style scoped>
.panel-promociones { padding: 1.5rem; border-radius: 1rem; background: #fffaf0; }
.cabecera, .acciones { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem; }
.cabecera { justify-content: space-between; }
.lista { padding: 0; list-style: none; }
.lista li { padding: 1rem 0; border-bottom: 1px solid #e5dbc5; }
.lista p { white-space: pre-wrap; overflow-wrap: anywhere; }
dialog { width: min(32rem, calc(100% - 2rem)); box-sizing: border-box; border: 1px solid #e5dbc5; border-radius: 1rem; padding: 1.5rem; background: #fffaf0; color: #214d3a; }
dialog::backdrop { background: #061b14b8; }
form { display: grid; gap: .5rem; }
input, textarea { box-sizing: border-box; width: 100%; padding: .6rem; }
button { cursor: pointer; padding: .5rem .8rem; }
.error { color: #a32222; }
</style>