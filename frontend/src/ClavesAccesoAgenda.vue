<script setup>
import { ref } from 'vue'
import { usarClaveAcceso } from './clavesAcceso.js'

const props = defineProps({ api: { type: Function, required: true } })
const ruta = '/api/admin/claves-acceso'
const dialogo = ref(null)
const claves = ref([])
const clave = ref('')
const error = ref('')
const aviso = ref('')
const ocupado = ref(false)
const claveParaQuitar = ref(null)
const fechaHora = new Intl.DateTimeFormat('es-AR', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Argentina/Mendoza' })
const fechaVisible = valor => fechaHora.format(new Date(valor))

async function ejecutar(accion) {
  if (ocupado.value) return
  ocupado.value = true
  error.value = ''
  aviso.value = ''
  try {
    await accion()
  } catch (fallo) {
    error.value = fallo.message
  } finally {
    ocupado.value = false
  }
}

function abrir() {
  dialogo.value.showModal()
  return ejecutar(async () => {
    claves.value = (await props.api(ruta)).claves
  })
}

function cerrar() {
  clave.value = ''
  claveParaQuitar.value = null
  dialogo.value?.close()
}

function registrar() {
  return ejecutar(async () => {
    const actual = clave.value
    clave.value = ''
    const opciones = await props.api(ruta + '/opciones', 'POST', { clave: actual })
    const credencial = await usarClaveAcceso(opciones, true)
    if (!dialogo.value?.open) return
    claves.value = (await props.api(ruta, 'POST', credencial)).claves
    aviso.value = 'Clave registrada. Ya podés ingresar con el desbloqueo del dispositivo.'
  })
}

function quitar() {
  return ejecutar(async () => {
    claves.value = (await props.api(ruta + '/' + claveParaQuitar.value, 'DELETE')).claves
    claveParaQuitar.value = null
    aviso.value = 'Clave de acceso quitada.'
  })
}

defineExpose({ abrir, cerrar })
</script>

<template>
  <dialog ref="dialogo" aria-labelledby="titulo-claves-acceso" @close="cerrar" @cancel="ocupado && $event.preventDefault()">
    <h2 id="titulo-claves-acceso">Claves de acceso</h2>
    <p>Usá la huella, el rostro o el PIN de tu dispositivo para iniciar sesión.</p>
    <p v-if="error" role="alert">{{ error }}</p>
    <p v-if="aviso" role="status">{{ aviso }}</p>
    <ul v-if="claves.length">
      <li v-for="item in claves" :key="item.id">
        <div><strong>Registrada el {{ fechaVisible(item.creada_en) }}</strong><br><small>{{ item.ultimo_uso ? 'Último ingreso: ' + fechaVisible(item.ultimo_uso) : 'Todavía sin ingresos' }}</small></div>
        <button v-if="claveParaQuitar !== item.id" type="button" class="boton-formulario boton-formulario--cancelar" :disabled="ocupado" @click="claveParaQuitar = item.id">Quitar clave</button>
        <div v-else class="acciones-formulario">
          <button type="button" class="boton-formulario boton-formulario--cancelar" :disabled="ocupado" @click="claveParaQuitar = null">Cancelar</button>
          <button type="button" class="boton-formulario boton-formulario--guardar" :disabled="ocupado" @click="quitar">Confirmar retiro</button>
        </div>
      </li>
    </ul>
    <form @submit.prevent="registrar">
      <label>Contraseña de Zenli
        <input v-model="clave" type="password" autocomplete="current-password" maxlength="72" required :disabled="ocupado">
      </label>
      <p>Confirmala una vez para registrar una clave de acceso.</p>
      <div class="acciones-formulario">
        <button type="button" class="boton-formulario boton-formulario--cancelar" :disabled="ocupado" @click="cerrar">Cerrar</button>
        <button type="submit" class="boton-formulario boton-formulario--guardar" :disabled="ocupado">Registrar clave de acceso</button>
      </div>
    </form>
  </dialog>
</template>

<style scoped>
dialog { width: min(36rem, calc(100% - 2rem)); max-height: 88dvh; box-sizing: border-box; padding: 1.2rem; border: 1px solid #e4dac3; border-radius: .8rem; background: #fffaf1; color: #15372a; }
dialog::backdrop { background: #071b13a8; }
form, label { display: grid; gap: .45rem; }
input { min-width: 0; padding: .55rem; border: 1px solid #a4ad9f; border-radius: .35rem; background: #fffdf7; font: inherit; }
ul { padding: 0; list-style: none; }
li { display: flex; justify-content: space-between; gap: .5rem; flex-wrap: wrap; border-top: 1px solid #e4dac3; padding: .55rem 0; }
[role=alert] { padding: .65rem; background: #f9e3df; color: #75271f; }
</style>
