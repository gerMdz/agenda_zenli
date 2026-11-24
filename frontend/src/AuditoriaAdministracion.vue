<script setup>
import { nextTick, onMounted, ref } from 'vue'
import { formatearFechaHora, formatearRangoTurno, importePesos, resumenAuditoria } from './formatoAuditoria.js'

defineProps({ csrf: { type: String, required: true } })

const pestana = ref('turnos')
const ocupado = ref(false)
const error = ref('')

// Filtros del historial de turnos
const filtroTipo = ref('todos')
const filtroDesde = ref('')
const filtroHasta = ref('')
const filtroCliente = ref('')
const historial = ref({ eventos: [], cancelados: [] })

// Detalle de un turno específico
const dialogoTurno = ref(null)
const detalleTurno = ref(null)
const cargandoTurno = ref(false)
const errorTurno = ref('')

// Auditoría de datos
const datos = ref(null)

async function consultarJson(ruta) {
  const respuesta = await fetch(ruta, { headers: { Accept: 'application/json' } })
  const contenido = respuesta.headers.get('content-type')?.includes('application/json') ? await respuesta.json() : null
  if (!respuesta.ok) throw new Error(contenido?.error || 'No se pudo cargar la auditoría.')
  return contenido
}

async function cargarHistorialTurnos() {
  if (ocupado.value) return
  ocupado.value = true
  error.value = ''
  try {
    const parametros = new URLSearchParams()
    if (filtroTipo.value && filtroTipo.value !== 'todos') parametros.set('tipo', filtroTipo.value)
    if (filtroDesde.value) parametros.set('desde', filtroDesde.value)
    if (filtroHasta.value) parametros.set('hasta', filtroHasta.value)
    if (filtroCliente.value.trim()) parametros.set('cliente', filtroCliente.value.trim())
    const qs = parametros.toString()
    historial.value = await consultarJson(`/api/admin/auditoria/turnos${qs ? `?${qs}` : ''}`)
  } catch (fallo) {
    error.value = fallo.message
  } finally {
    ocupado.value = false
  }
}

async function cargarAuditoriaDatos() {
  if (ocupado.value) return
  ocupado.value = true
  error.value = ''
  try {
    datos.value = await consultarJson('/api/admin/auditoria/datos')
  } catch (fallo) {
    error.value = fallo.message
  } finally {
    ocupado.value = false
  }
}

function cambiarPestana(nueva) {
  pestana.value = nueva
  error.value = ''
  if (nueva === 'datos' && !datos.value) {
    cargarAuditoriaDatos()
  }
}

function limpiarFiltros() {
  filtroTipo.value = 'todos'
  filtroDesde.value = ''
  filtroHasta.value = ''
  filtroCliente.value = ''
  cargarHistorialTurnos()
}

async function abrirDetalleTurno(turnoId) {
  errorTurno.value = ''
  detalleTurno.value = null
  cargandoTurno.value = true
  await nextTick()
  dialogoTurno.value?.showModal()
  try {
    detalleTurno.value = await consultarJson(`/api/admin/auditoria/turnos/${turnoId}`)
  } catch (fallo) {
    errorTurno.value = fallo.message
  } finally {
    cargandoTurno.value = false
  }
}

function cerrarDetalleTurno() {
  if (dialogoTurno.value?.open) dialogoTurno.value.close()
  detalleTurno.value = null
  errorTurno.value = ''
}

function claseBadge(evento) {
  if (evento.tipo === 'CANCELADO') return 'cancelado'
  if (evento.cambio_sena) return 'sena'
  if (evento.tipo === 'CREADO') return 'creado'
  if (evento.tipo === 'PREP_EDITADA' || evento.tipo === 'PREP_ELIMINADA') return 'preparacion'
  return 'editado'
}

onMounted(() => {
  cargarHistorialTurnos()
})
</script>

<template>
  <section class="panel-auditoria" aria-labelledby="titulo-auditoria">
    <div class="cabecera">
      <div>
        <h2 id="titulo-auditoria">Auditoría</h2>
        <small>Visible solo para cuentas administradoras.</small>
      </div>
      <div class="pestanas" role="tablist" aria-label="Vistas de auditoría">
        <button
          type="button"
          role="tab"
          :aria-selected="pestana === 'turnos'"
          :class="{ secundario: pestana !== 'turnos' }"
          :disabled="ocupado"
          @click="cambiarPestana('turnos')"
        >
          Historial de turnos
        </button>
        <button
          type="button"
          role="tab"
          :aria-selected="pestana === 'datos'"
          :class="{ secundario: pestana !== 'datos' }"
          :disabled="ocupado"
          @click="cambiarPestana('datos')"
        >
          Auditoría de datos
        </button>
      </div>
    </div>

    <p v-if="error" class="error" role="alert">{{ error }}</p>

    <div v-if="pestana === 'turnos'" class="bloque-tab">
      <p class="ayuda">Cada creación, edición (incluida la seña), cambio de preparación y cancelación de turnos queda registrada con su autora y fecha.</p>
      <form class="filtros" @submit.prevent="cargarHistorialTurnos">
        <label>
          Tipo
          <select v-model="filtroTipo" :disabled="ocupado">
            <option value="todos">Todos los cambios</option>
            <option value="sena">Cambios de seña</option>
            <option value="cancelado">Cancelaciones</option>
            <option value="asistencia">Asistencia</option>
            <option value="editado">Ediciones</option>
            <option value="creado">Creaciones</option>
            <option value="preparacion">Preparaciones</option>
          </select>
        </label>
        <label>
          Desde
          <input v-model="filtroDesde" type="date" :disabled="ocupado">
        </label>
        <label>
          Hasta
          <input v-model="filtroHasta" type="date" :disabled="ocupado">
        </label>
        <label>
          Cliente
          <input v-model="filtroCliente" type="search" maxlength="150" placeholder="Buscar por cliente…" :disabled="ocupado">
        </label>
        <div class="acciones-filtros">
          <button :disabled="ocupado">Filtrar</button>
          <button type="button" class="secundario" :disabled="ocupado" @click="limpiarFiltros">Limpiar</button>
        </div>
      </form>

      <p v-if="ocupado" role="status">Cargando historial…</p>
      <p v-else-if="!historial.eventos.length" class="vacio">No hay registros que coincidan con los filtros elegidos.</p>
      <ul v-else class="lista-eventos">
        <li v-for="evento in historial.eventos" :key="evento.id" class="evento">
          <div class="evento-cuerpo">
            <div class="evento-encabezado">
              <span class="badge" :class="claseBadge(evento)">{{ evento.etiqueta }}</span>
              <strong>{{ evento.resumen }}</strong>
            </div>
            <small class="evento-autor">
              Por <strong>{{ evento.autor || 'Sin dato' }}</strong> · {{ formatearFechaHora(evento.fecha) }}
            </small>
            <small class="evento-turno">
              Turno: <strong>{{ evento.turno.cliente }}</strong> · {{ evento.turno.servicio }} · {{ formatearRangoTurno(evento.turno.inicio, evento.turno.fin) }}
              <template v-if="evento.turno.profesionales"> · {{ evento.turno.profesionales }}</template>
              <span v-if="evento.turno.cancelado" class="badge cancelado">Cancelado</span>
            </small>
            <ul v-if="evento.cambios && evento.cambios.length > 1" class="lista-cambios">
              <li v-for="(cambio, indice) in evento.cambios" :key="indice">{{ cambio }}</li>
            </ul>
          </div>
          <button type="button" class="secundario" :disabled="ocupado" @click="abrirDetalleTurno(evento.turno.id)">
            Ver turno
          </button>
        </li>
      </ul>
    </div>

    <div v-else class="bloque-tab">
      <div class="barra-sub">
        <p class="ayuda">Registro de quién creó y quién modificó por última vez cada dato del panel.</p>
        <button type="button" class="secundario" :disabled="ocupado" @click="cargarAuditoriaDatos">Actualizar</button>
      </div>
      <p v-if="ocupado && !datos" role="status">Cargando auditoría de datos…</p>
      <template v-if="datos">
        <div class="grupo-datos">
          <h3>Configuración general</h3>
          <ul class="lista-datos">
            <li>
              <strong>Portada pública</strong>
              <small>{{ resumenAuditoria(datos.portada, true) || 'Sin modificaciones registradas' }}</small>
            </li>
            <li>
              <strong>Tiempo de preparación</strong>
              <small>{{ resumenAuditoria(datos.preparacion, true) || 'Sin configuración guardada' }}</small>
            </li>
            <li>
              <strong>Presentación de profesionales en portada</strong>
              <small>{{ resumenAuditoria(datos.presentacion_profesionales, true) || 'Sin modificaciones registradas' }}</small>
            </li>
          </ul>
        </div>

        <div class="grupo-datos">
          <h3>Servicios ({{ datos.servicios.length }})</h3>
          <p v-if="!datos.servicios.length" class="vacio">Sin servicios cargados.</p>
          <ul v-else class="lista-datos">
            <li v-for="item in datos.servicios" :key="item.id">
              <strong>{{ item.nombre }} <span class="badge" :class="item.visible ? 'creado' : 'neutro'">{{ item.visible ? 'Visible' : 'Oculto' }}</span></strong>
              <small>{{ resumenAuditoria(item.auditoria) || 'Sin registros' }}</small>
            </li>
          </ul>
        </div>

        <div class="grupo-datos">
          <h3>Promociones ({{ datos.promociones.length }})</h3>
          <p v-if="!datos.promociones.length" class="vacio">Sin promociones cargadas.</p>
          <ul v-else class="lista-datos">
            <li v-for="item in datos.promociones" :key="item.id">
              <strong>
                {{ item.nombre }}
                <span v-if="item.eliminado" class="badge cancelado">Eliminada</span>
                <span v-else class="badge" :class="item.visible ? 'creado' : 'neutro'">{{ item.visible ? 'Visible' : 'Oculta' }}</span>
              </strong>
              <small>{{ resumenAuditoria(item.auditoria, true) || 'Sin registros' }}</small>
            </li>
          </ul>
        </div>

        <div class="grupo-datos">
          <h3>Espacios de atención ({{ datos.espacios.length }})</h3>
          <p v-if="!datos.espacios.length" class="vacio">Sin espacios cargados.</p>
          <ul v-else class="lista-datos">
            <li v-for="item in datos.espacios" :key="item.id">
              <strong>{{ item.nombre }} <span v-if="!item.activo" class="badge neutro">Inactivo</span></strong>
              <small>{{ resumenAuditoria(item.auditoria) || 'Sin registros todavía' }}</small>
            </li>
          </ul>
        </div>

        <div class="grupo-datos">
          <h3>Profesionales y horarios ({{ datos.profesionales.length }})</h3>
          <p v-if="!datos.profesionales.length" class="vacio">Sin profesionales cargadas.</p>
          <ul v-else class="lista-datos">
            <li v-for="item in datos.profesionales" :key="item.id">
              <strong>{{ item.nombre }} <span v-if="!item.activa" class="badge neutro">Dada de baja</span></strong>
              <small>Perfil: {{ resumenAuditoria(item.auditoria, true) || 'Sin registros todavía' }}</small>
              <small v-if="resumenAuditoria(item.horarios_auditoria)">Horarios: {{ resumenAuditoria(item.horarios_auditoria) }}</small>
              <small v-if="resumenAuditoria(item.imagen_auditoria, true)">Imagen de portada: {{ resumenAuditoria(item.imagen_auditoria, true) }}</small>
            </li>
          </ul>
        </div>

        <div class="grupo-datos">
          <h3>Cuentas de acceso ({{ datos.cuentas.length }})</h3>
          <p v-if="!datos.cuentas.length" class="vacio">Sin cuentas registradas.</p>
          <ul v-else class="lista-datos">
            <li v-for="item in datos.cuentas" :key="item.id">
              <strong>
                {{ item.email }}
                <span v-if="item.profesional"> · {{ item.profesional }}</span>
                <span class="badge" :class="item.activo ? 'creado' : 'cancelado'">{{ item.activo ? 'Activa' : 'Desactivada' }}</span>
                <span v-if="item.admin" class="badge editado">Admin</span>
              </strong>
              <small>{{ resumenAuditoria(item.auditoria, true) || 'Sin registros todavía' }}</small>
            </li>
          </ul>
        </div>
      </template>
    </div>

    <dialog ref="dialogoTurno" class="modal-historial" aria-labelledby="titulo-modal-historial" @close="cerrarDetalleTurno">
      <div class="modal-cabecera">
        <div>
          <span class="modal-etiqueta">HISTORIAL DEL TURNO</span>
          <h2 id="titulo-modal-historial">{{ detalleTurno ? `${detalleTurno.turno.cliente} · ${detalleTurno.turno.servicio}` : 'Historial del turno' }}</h2>
          <p v-if="detalleTurno">
            {{ formatearRangoTurno(detalleTurno.turno.inicio, detalleTurno.turno.fin) }}
            <template v-if="detalleTurno.turno.espacio"> · {{ detalleTurno.turno.espacio }}</template>
            <template v-if="detalleTurno.turno.profesionales"> · {{ detalleTurno.turno.profesionales }}</template>
          </p>
        </div>
        <button type="button" class="cerrar-modal" aria-label="Cerrar historial" @click="cerrarDetalleTurno">✕</button>
      </div>
      <p v-if="cargandoTurno" role="status">Cargando historial del turno…</p>
      <p v-if="errorTurno" class="error" role="alert">{{ errorTurno }}</p>
      <template v-if="detalleTurno">
        <div class="resumen-estado-turno">
          <span class="badge" :class="detalleTurno.turno.cancelado ? 'cancelado' : 'creado'">
            {{ detalleTurno.turno.cancelado ? 'Cancelado' : 'Activo' }}
          </span>
          <small>
            Seña: {{ detalleTurno.turno.sena_pagada ? 'pagada' : 'no pagada' }}
            <template v-if="detalleTurno.turno.sena_importe_centavos != null"> · $ {{ importePesos(detalleTurno.turno.sena_importe_centavos) }}</template>
          </small>
        </div>
        <ol class="linea-tiempo">
          <li v-for="ev in detalleTurno.eventos" :key="ev.id">
            <div class="evento-encabezado">
              <span class="badge" :class="claseBadge(ev)">{{ ev.etiqueta }}</span>
              <strong>{{ ev.resumen }}</strong>
            </div>
            <small class="evento-autor">Por <strong>{{ ev.autor || 'Sin dato' }}</strong> · {{ formatearFechaHora(ev.fecha) }}</small>
            <ul v-if="ev.cambios?.length" class="lista-cambios">
              <li v-for="(cambio, indice) in ev.cambios" :key="indice">{{ cambio }}</li>
            </ul>
          </li>
        </ol>
      </template>
      <div class="modal-pie">
        <button type="button" class="secundario" @click="cerrarDetalleTurno">Cerrar</button>
      </div>
    </dialog>
  </section>
</template>

<style scoped>
.panel-auditoria { background: #fffaf1; padding: clamp(1rem, 3vw, 1.6rem); border: 1px solid #e4dac3; border-radius: .9rem; }
.cabecera { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
.pestanas { display: flex; flex-wrap: wrap; gap: .4rem; }
h2, h3 { font-family: Georgia, serif; margin: 0 0 .35rem; }
h3 { font-size: 1.05rem; margin-top: 1.25rem; padding-bottom: .35rem; border-bottom: 1px solid #e4dac3; }
small { font-size: .8rem; color: #526255; }
.ayuda { margin: 0 0 .9rem; color: #526255; font-size: .9rem; }
.barra-sub { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: .75rem; }
.filtros { display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: .65rem; align-items: end; padding: .85rem; margin-bottom: 1rem; border: 1px solid #e4dac3; border-radius: .6rem; background: #fffdf7; }
.filtros label { display: grid; gap: .3rem; font-weight: 650; font-size: .84rem; }
.filtros input, .filtros select { box-sizing: border-box; width: 100%; min-width: 0; padding: .5rem .6rem; border: 1px solid #aaab98; border-radius: .4rem; background: #fffdf7; color: #15372a; font: inherit; }
.acciones-filtros { display: flex; flex-wrap: wrap; gap: .4rem; }
button { width: fit-content; padding: .52rem .82rem; border: 1px solid #214d3a; border-radius: .4rem; background: #214d3a; color: #fffdfa; cursor: pointer; font: inherit; font-weight: 650; font-size: .87rem; }
button.secundario { background: #fff9ec; color: #214d3a; }
button:disabled { opacity: .54; cursor: default; }
button:focus-visible, input:focus-visible, select:focus-visible { outline: 2px solid #8f631e; outline-offset: 2px; }
.error { background: #f9e3df; color: #75271f; border-radius: .4rem; padding: .65rem .8rem; }
.vacio { color: #526255; font-style: italic; margin: .8rem 0; }
.lista-eventos, .lista-datos, .linea-tiempo { list-style: none; padding: 0; margin: 0; }
.lista-eventos > li.evento { display: flex; align-items: flex-start; justify-content: space-between; gap: .8rem; padding: .75rem 0; border-bottom: 1px solid #e4dac3; }
.evento-cuerpo { min-width: 0; overflow-wrap: anywhere; display: grid; gap: .25rem; }
.evento-encabezado { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem; }
.evento-autor, .evento-turno { display: block; }
.lista-cambios { margin: .25rem 0 0; padding-left: 1.15rem; font-size: .84rem; color: #2c4a3c; }
.lista-cambios li { margin-block: .12rem; }
.lista-datos li { display: grid; gap: .2rem; padding: .55rem 0; border-bottom: 1px solid #e4dac3; font-size: .88rem; overflow-wrap: anywhere; }
.badge { display: inline-block; padding: .12rem .45rem; border-radius: .3rem; font-size: .72rem; font-weight: 700; vertical-align: middle; }
.badge.creado { background: #e4efdf; color: #194832; }
.badge.sena { background: #f4e6c4; color: #6c460d; }
.badge.cancelado { background: #f9e3df; color: #75271f; }
.badge.editado { background: #e5ecf4; color: #1e4266; }
.badge.preparacion { background: #efe7dc; color: #5e4422; }
.badge.neutro { background: #ece7db; color: #526255; }
.modal-historial { width: min(42rem, calc(100% - 2rem)); max-height: min(90svh, 58rem); box-sizing: border-box; padding: clamp(1.1rem, 4vw, 1.8rem); border: 1px solid #e4dac3; border-radius: 1rem; background: #fffaf1; color: #15372a; box-shadow: 0 1.5rem 5rem #08271c55; }
.modal-historial::backdrop { background: #08271cb8; }
.modal-cabecera { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; padding-bottom: .9rem; border-bottom: 1px solid #e4dac3; }
.modal-cabecera h2 { font-size: clamp(1.3rem, 3.5vw, 1.7rem); margin: .2rem 0; }
.modal-cabecera p { margin: 0; color: #526255; font-size: .88rem; }
.modal-etiqueta { color: #8f631e; font-size: .7rem; font-weight: 750; letter-spacing: .12em; }
button.cerrar-modal { flex-shrink: 0; border: 1px solid #e4dac3; border-radius: 50%; width: 2.3rem; height: 2.3rem; padding: 0; background: #fffdf7; color: #214d3a; }
.resumen-estado-turno { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem; margin-block: .85rem; }
.linea-tiempo > li { display: grid; gap: .25rem; padding: .7rem 0 .7rem .85rem; border-left: 3px solid #d5c8aa; border-bottom: 1px solid #efe7d5; }
.modal-pie { display: flex; justify-content: flex-end; margin-top: 1.2rem; padding-top: .9rem; border-top: 1px solid #e4dac3; }
@media (max-width: 46rem) {
  .lista-eventos > li.evento { flex-direction: column; }
}
</style>
