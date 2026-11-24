<script setup>
import { ref } from 'vue'

const props = defineProps({ api: { type: Function, required: true } })
const emit = defineEmits(['guardada'])
const dialogo = ref(null)
const id = ref(null)
const datos = ref({ nombre: '', telefono: '', email: '' })
const ocupado = ref(false)
const error = ref('')

function abrir(cliente = {}) {
  id.value = cliente.id ?? null
  datos.value = { nombre: cliente.nombre ?? '', telefono: cliente.telefono ?? '', email: cliente.email ?? '' }
  error.value = ''
  dialogo.value.showModal()
}

function cerrar() {
  dialogo.value?.close()
}

async function guardar() {
  if (ocupado.value) return
  ocupado.value = true
  error.value = ''
  try {
    const ruta = '/api/admin/agenda/clientes' + (id.value ? '/' + id.value : '')
    const ficha = await props.api(ruta, id.value ? 'PUT' : 'POST', datos.value)
    const abierta = dialogo.value?.open
    cerrar()
    if (abierta) emit('guardada', ficha)
  } catch (fallo) {
    error.value = fallo.message
  } finally {
    ocupado.value = false
  }
}

defineExpose({ abrir, cerrar })
</script>

<template>
  <dialog ref="dialogo" aria-label="Datos de la ficha del cliente" @cancel="ocupado && $event.preventDefault()">
    <h2>{{ id ? 'Editar ficha' : 'Nueva ficha' }}</h2>
    <p v-if="error" role="alert">{{ error }}</p>
    <form @submit.prevent="guardar">
      <label>Nombre <input v-model.trim="datos.nombre" required maxlength="150" autofocus :disabled="ocupado"></label>
      <label>Teléfono (opcional) <input v-model.trim="datos.telefono" type="tel" maxlength="30" :disabled="ocupado"></label>
      <label>Correo (opcional) <input v-model.trim="datos.email" type="email" maxlength="180" :disabled="ocupado"></label>
      <div class="acciones-formulario">
        <button type="button" class="boton-formulario boton-formulario--cancelar" :disabled="ocupado" @click="cerrar">Cancelar</button>
        <button type="submit" class="boton-formulario boton-formulario--guardar" :disabled="ocupado">Guardar ficha</button>
      </div>
    </form>
  </dialog>
</template>

<style scoped>
dialog { width: min(36rem, calc(100% - 2rem)); max-height: 88dvh; box-sizing: border-box; padding: 1.2rem; border: 1px solid #e4dac3; border-radius: .8rem; background: #fffaf1; color: #15372a; }
dialog::backdrop { background: #071b13a8; }
h2 { margin-block: .4rem; }
form, label { display: grid; gap: .45rem; }
input { min-width: 0; padding: .55rem; border: 1px solid #a4ad9f; border-radius: .35rem; background: #fffdf7; font: inherit; }
[role=alert] { padding: .65rem; background: #f9e3df; color: #75271f; }
</style>
