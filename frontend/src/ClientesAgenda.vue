<script setup>
import { ref, watch } from 'vue'
import FichaClienteDialog from './FichaClienteDialog.vue'

const props = defineProps({
  api: { type: Function, required: true },
  fichaId: { type: String, default: null },
})
const emit = defineEmits(['seleccionar', 'ver-turno'])
const ruta = '/api/admin/agenda/clientes'
const nombre = ref('')
const incluirArchivados = ref(false)
const listado = ref({ clientes: [], hay_mas: false })
const ficha = ref(null)
const ocupado = ref(false)
const error = ref('')
const aviso = ref('')
const dialogoFicha = ref(null)
const dialogoObservacion = ref(null)
const dialogoArchivo = ref(null)
const texto = ref('')
const estados = { programado: 'Programado', atendido: 'Atendido', ausente: 'Ausente' }
const fechaHora = new Intl.DateTimeFormat('es-AR', {
  dateStyle: 'short', timeStyle: 'short', hourCycle: 'h23', timeZone: 'America/Argentina/Mendoza',
})
const fechaVisible = valor => fechaHora.format(new Date(valor))

async function ejecutar(accion) {
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

async function cargarListado() {
  listado.value = await props.api(ruta + '/buscar', 'POST', {
    nombre: nombre.value,
    archivados: incluirArchivados.value,
  })
}

watch(() => props.fichaId, id => ejecutar(async () => {
  ficha.value = null
  if (id) ficha.value = await props.api(ruta + '/' + id)
  else await cargarListado()
}), { immediate: true })

function editar() {
  dialogoFicha.value.abrir(ficha.value?.cliente)
}

function fichaGuardada(resultado) {
  if (props.fichaId) {
    ficha.value = resultado
    aviso.value = 'Ficha guardada.'
  } else {
    emit('seleccionar', resultado.cliente.id)
  }
}

function abrirObservacion() {
  texto.value = ''
  error.value = ''
  dialogoObservacion.value.showModal()
}

function guardarObservacion() {
  return ejecutar(async () => {
    ficha.value = await props.api(ruta + '/' + ficha.value.cliente.id + '/observaciones', 'POST', { texto: texto.value })
    dialogoObservacion.value.close()
    texto.value = ''
    aviso.value = 'Observación agregada.'
  })
}

function cambiarArchivo() {
  return ejecutar(async () => {
    const id = ficha.value.cliente.id
    const reactivar = Boolean(ficha.value.cliente.archivado_en)
    await props.api(ruta + '/' + id + (reactivar ? '/reactivar' : ''), reactivar ? 'POST' : 'DELETE', {})
    dialogoArchivo.value.close()
    ficha.value = await props.api(ruta + '/' + id)
    aviso.value = reactivar ? 'Ficha reactivada.' : 'Ficha archivada.'
  })
}
</script>

<template>
  <section class="clientes-agenda" aria-labelledby="titulo-clientes-agenda">
    <header class="barra">
      <h1 id="titulo-clientes-agenda">{{ ficha ? ficha.cliente.nombre : 'Clientes' }}</h1>
      <button v-if="fichaId" type="button" class="secundario" :disabled="ocupado" @click="emit('seleccionar', null)">Volver a clientes</button>
      <button v-else type="button" :disabled="ocupado" @click="editar">Nueva ficha</button>
    </header>
    <p v-if="ocupado && !ficha" role="status">Cargando…</p>
    <p v-if="error" role="alert">{{ error }}</p>
    <p v-if="aviso" role="status">{{ aviso }}</p>

    <template v-if="!fichaId">
      <form class="busqueda" @submit.prevent="ejecutar(cargarListado)">
        <label>Nombre del cliente <input v-model="nombre" type="search" maxlength="150" autocomplete="off" :disabled="ocupado"></label>
        <label class="casilla"><input v-model="incluirArchivados" type="checkbox" :disabled="ocupado" @change="ejecutar(cargarListado)"> Incluir fichas archivadas</label>
        <button type="submit" :disabled="ocupado">Buscar</button>
      </form>
      <p v-if="!ocupado && !listado.clientes.length">No se encontraron fichas.</p>
      <ul v-else class="filas">
        <li v-for="cliente in listado.clientes" :key="cliente.id">
          <div><strong>{{ cliente.nombre }}</strong><small v-if="cliente.telefono">{{ cliente.telefono }}</small><small v-if="cliente.email">{{ cliente.email }}</small><small v-if="cliente.archivado_en">Archivada</small></div>
          <button type="button" class="secundario" :disabled="ocupado" @click="emit('seleccionar', cliente.id)">Ver ficha</button>
        </li>
      </ul>
      <p v-if="listado.hay_mas">Ingresá un nombre más completo para acotar la búsqueda.</p>
    </template>

    <template v-if="ficha">
      <div class="barra">
        <p v-if="ficha.cliente.archivado_en">Ficha archivada</p>
        <div v-if="ficha.editable" class="acciones">
          <button v-if="!ficha.cliente.archivado_en" type="button" class="secundario" :disabled="ocupado" @click="editar">Editar ficha</button>
          <button type="button" class="secundario" :disabled="ocupado" @click="error = ''; dialogoArchivo.showModal()">{{ ficha.cliente.archivado_en ? 'Reactivar ficha' : 'Archivar ficha' }}</button>
        </div>
      </div>
      <dl v-if="ficha.cliente.telefono || ficha.cliente.email" class="contacto">
        <template v-if="ficha.cliente.telefono"><dt>Teléfono</dt><dd>{{ ficha.cliente.telefono }}</dd></template>
        <template v-if="ficha.cliente.email"><dt>Correo</dt><dd>{{ ficha.cliente.email }}</dd></template>
      </dl>
      <h2>Historial de turnos</h2>
      <p v-if="!ficha.turnos.length">Todavía no hay turnos vinculados a esta ficha.</p>
      <ul v-else class="filas">
        <li v-for="turno in ficha.turnos" :key="turno.id">
          <div><strong>{{ turno.servicio }}</strong><small>{{ fechaVisible(turno.inicio) }} · {{ turno.cancelado_en ? 'Cancelado' : estados[turno.asistencia] }}</small></div>
          <button v-if="!turno.cancelado_en" type="button" class="secundario" :disabled="ocupado" @click="emit('ver-turno', turno)">Ver turno</button>
        </li>
      </ul>
      <p v-if="ficha.mas_turnos">Se muestran los últimos 100 turnos.</p>
      <div class="barra">
        <h2>Observaciones</h2>
        <button v-if="!ficha.cliente.archivado_en" type="button" class="secundario" :disabled="ocupado" @click="abrirObservacion">Agregar observación</button>
      </div>
      <p v-if="!ficha.observaciones.length">No hay observaciones para mostrar.</p>
      <ul v-else class="filas notas">
        <li v-for="nota in ficha.observaciones" :key="nota.id"><div><small>{{ fechaVisible(nota.creado_en) }}</small><p>{{ nota.texto }}</p></div></li>
      </ul>
      <p v-if="ficha.mas_observaciones">Se muestran las últimas 100 observaciones.</p>
    </template>

    <FichaClienteDialog ref="dialogoFicha" :api="api" @guardada="fichaGuardada" />
    <dialog ref="dialogoObservacion" aria-labelledby="titulo-observacion" @cancel="ocupado && $event.preventDefault()" @close="error = ''">
      <h2 id="titulo-observacion">Agregar observación</h2>
      <p v-if="error" role="alert">{{ error }}</p>
      <form @submit.prevent="guardarObservacion">
        <label>Observación <textarea v-model.trim="texto" rows="4" required maxlength="2000" autofocus :disabled="ocupado"></textarea></label>
        <p>La pueden ver tu cuenta y administración.</p>
        <div class="acciones-formulario">
          <button type="button" class="boton-formulario boton-formulario--cancelar" :disabled="ocupado" @click="dialogoObservacion.close()">Cancelar</button>
          <button type="submit" class="boton-formulario boton-formulario--guardar" :disabled="ocupado">Guardar observación</button>
        </div>
      </form>
    </dialog>
    <dialog ref="dialogoArchivo" aria-labelledby="titulo-archivo-ficha" @cancel="ocupado && $event.preventDefault()" @close="error = ''">
      <h2 id="titulo-archivo-ficha">{{ ficha?.cliente.archivado_en ? 'Reactivar ficha' : 'Archivar ficha' }}</h2>
      <p v-if="ficha?.cliente.archivado_en">La ficha volverá a aparecer entre los clientes activos.</p>
      <p v-else>La ficha saldrá del catálogo activo. Se conservarán su historial y sus observaciones.</p>
      <p v-if="error" role="alert">{{ error }}</p>
      <div class="acciones-formulario">
        <button type="button" class="boton-formulario boton-formulario--cancelar" :disabled="ocupado" @click="dialogoArchivo.close()">Cancelar</button>
        <button type="button" class="boton-formulario boton-formulario--guardar" :disabled="ocupado" @click="cambiarArchivo">{{ ficha?.cliente.archivado_en ? 'Reactivar' : 'Archivar' }}</button>
      </div>
    </dialog>
  </section>
</template>

<style scoped>
.clientes-agenda { background: #fffaf1; color: #15372a; border: 1px solid #e4dac3; border-radius: .9rem; padding: 1rem; }
.barra, .acciones, .filas li { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: .6rem; }
h1, h2 { margin-block: .4rem; }
form, label:not(.casilla) { display: grid; gap: .45rem; }
.busqueda { margin-block: 1rem; }
input, textarea { box-sizing: border-box; font: inherit; }
input:not([type=checkbox]), textarea { min-width: 0; padding: .55rem; border: 1px solid #a4ad9f; border-radius: .35rem; background: #fffdf7; }
button { width: fit-content; padding: .55rem .75rem; border: 1px solid #214d3a; border-radius: .4rem; background: #214d3a; color: white; cursor: pointer; }
button.secundario { background: #fffaf1; color: #214d3a; }
.filas { list-style: none; padding: 0; }
.filas li { border-top: 1px solid #e4dac3; padding-block: .6rem; overflow-wrap: anywhere; }
small { display: block; color: #526255; }
.notas p { white-space: pre-wrap; margin: .25rem 0 0; }
.contacto { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: .35rem .8rem; }
dt { font-weight: 700; }
dd { margin: 0; overflow-wrap: anywhere; }
dialog { width: min(36rem, calc(100% - 2rem)); max-height: 88dvh; box-sizing: border-box; padding: 1.2rem; border: 1px solid #e4dac3; border-radius: .8rem; background: #fffaf1; color: #15372a; }
dialog::backdrop { background: #071b13a8; }
</style>
