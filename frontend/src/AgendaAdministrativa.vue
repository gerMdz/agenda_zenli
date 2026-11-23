<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import LayoutAdministracion from './LayoutAdministracion.vue'
import EntradaHora from './EntradaHora.vue'
import { formatearFechaHora, formatearRangoTurno, importePesos } from './formatoAuditoria.js'

function seccionDeAgenda() {
  return window.location.hash === '#mensajes' ? 'mensajes' : 'agenda'
}

const seccionAgenda = ref(seccionDeAgenda())

function actualizarSeccion() {
  seccionAgenda.value = seccionDeAgenda()
}

onBeforeUnmount(() => window.removeEventListener('hashchange', actualizarSeccion))

const zona = 'America/Argentina/Mendoza'
const fecha = ref(new Intl.DateTimeFormat('sv-SE', { timeZone: zona }).format(new Date()))
const calendario = new Intl.DateTimeFormat('sv-SE', { timeZone: zona })
const reloj = new Intl.DateTimeFormat('es-AR', { timeZone: zona, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
const datos = ref(null)
const vista = ref('semana')
const soloMios = ref(false)
const dialogo = ref(null)
const modalAbierto = ref(false)
const dialogoBloqueo = ref(null)
const modoBloqueo = ref(false)
const dialogoMensaje = ref(null)
const mensajeAbierto = ref(false)
const dialogoPreparacion = ref(null)
const preparacionEditada = ref(null)
const inicioPreparacion = ref('')
const finPreparacion = ref('')
const dialogoHistorial = ref(null)
const historialTurno = ref(null)
const cargandoHistorial = ref(false)
const errorHistorial = ref('')
const dialogoHistorialPeriodo = ref(null)
const historialPeriodo = ref(null)
const cargandoPeriodo = ref(false)
const errorPeriodo = ref('')
const bloqueoNuevo = ref({ profesional: '', fecha: '', todo_el_dia: false, hora_inicio: '', hora_fin: '' })
const nombreDia = new Intl.DateTimeFormat('es-AR', { weekday: 'short', timeZone: 'UTC' })
const tituloMes = new Intl.DateTimeFormat('es-AR', { month: 'long', year: 'numeric', timeZone: 'UTC' })
const etiquetaDia = new Intl.DateTimeFormat('es-AR', { day: 'numeric', month: 'short', timeZone: 'UTC' })
const vistas = [{ id: 'dia', nombre: 'Día' }, { id: 'semana', nombre: 'Semana' }, { id: 'mes', nombre: 'Mes' }]

function diaUtc(texto) {
  return new Date(`${texto}T00:00:00Z`)
}

function sumarDias(dia, cantidad) {
  return new Date(dia.getTime() + cantidad * 86_400_000)
}

function claveDia(dia) {
  return dia.toISOString().slice(0, 10)
}

const periodo = computed(() => {
  const dia = diaUtc(fecha.value)
  if (vista.value === 'mes') {
    const inicio = new Date(dia)
    inicio.setUTCDate(1)
    const siguiente = new Date(inicio)
    siguiente.setUTCMonth(siguiente.getUTCMonth() + 1)
    return { inicio, fin: sumarDias(siguiente, -1) }
  }
  if (vista.value === 'semana') {
    const inicio = sumarDias(dia, -((dia.getUTCDay() + 6) % 7))
    return { inicio, fin: sumarDias(inicio, 6) }
  }
  return { inicio: dia, fin: dia }
})

const encabezadoPeriodo = computed(() => {
  const { inicio, fin } = periodo.value
  if (vista.value === 'mes') return tituloMes.format(inicio)
  if (vista.value === 'dia') return etiquetaDia.format(inicio)
  return `${etiquetaDia.format(inicio)} – ${etiquetaDia.format(fin)}`
})

function esPropio(turno) {
  return turno.equipo.includes(usuario.value?.profesional_id)
}

const turnosVisibles = computed(() => {
  const bloqueos = (datos.value?.bloqueos ?? []).map(bloqueo => ({
    ...bloqueo,
    id: `bloqueo-${bloqueo.id}`,
    equipo: [bloqueo.profesional_id],
    bloqueado: true,
  }))
  const preparaciones = (datos.value?.preparaciones ?? []).map(item => ({
    ...item, id: `preparacion-${item.id}`, preparacionId: item.id,
    esPreparacion: true, equipo: datos.value?.turnos.find(turno => turno.id === item.turno_id)?.equipo ?? [], servicio: 'Preparación del espacio',
  }))
  return [...(datos.value?.turnos ?? []), ...bloqueos, ...preparaciones]
    .filter(turno => !soloMios.value || esPropio(turno))
    .sort((a, b) => new Date(a.inicio) - new Date(b.inicio))
})

function horarioVisible(turno) {
  if (turno.todo_el_dia) return 'Todo el día'
  const fin = reloj.format(new Date(turno.fin))
  return `${reloj.format(new Date(turno.inicio))}–${turno.bloqueado && fin === '00:00' ? '24:00' : fin}`
}

function turnosEnDia(dia) {
  return turnosVisibles.value.filter(turno =>
    calendario.format(new Date(turno.inicio)) <= dia &&
    calendario.format(new Date(new Date(turno.fin).getTime() - 1)) >= dia,
  )
}

const dias = computed(() => {
  const { inicio, fin } = periodo.value
  const resultado = []
  for (let dia = inicio; dia <= fin; dia = sumarDias(dia, 1)) {
    const clave = claveDia(dia)
    const turnos = turnosEnDia(clave)
    const bloqueos = turnos.filter(turno => turno.bloqueado).length
    const preparaciones = turnos.filter(turno => turno.esPreparacion).length
    resultado.push({
      clave, numero: dia.getUTCDate(), nombre: nombreDia.format(dia), turnos, bloqueos,
      citas: turnos.length - bloqueos - preparaciones, propios: turnos.filter(turno => !turno.bloqueado && !turno.esPreparacion && esPropio(turno)).length,
    })
  }
  return resultado
})
const turnosDia = computed(() => turnosEnDia(fecha.value))
const offsetMes = computed(() => (periodo.value.inicio.getUTCDay() + 6) % 7)

function cambiarFecha(nuevaFecha) {
  if (!nuevaFecha) return
  fecha.value = nuevaFecha
  return ejecutar(cargarDia)
}

function cambiarVista(nuevaVista) {
  if (nuevaVista === vista.value) return
  vista.value = nuevaVista
  return ejecutar(cargarDia)
}

function navegar(direccion) {
  if (vista.value === 'mes') {
    const inicio = new Date(periodo.value.inicio)
    inicio.setUTCMonth(inicio.getUTCMonth() + direccion)
    return cambiarFecha(claveDia(inicio))
  }
  return cambiarFecha(claveDia(sumarDias(diaUtc(fecha.value), direccion * (vista.value === 'semana' ? 7 : 1))))
}
const csrf = ref('')
const usuario = ref(null)
const editando = ref(null)
const fechaTurno = ref('')
const bandeja = ref({ contactos: [], mensajes: [] })
const destinatario = ref('')
const texto = ref('')
const ocupado = ref(false)
const error = ref('')
const aviso = ref('')
const cliente = ref('')
const servicio = ref('')
const hora = ref('')
const duracion = ref('')
const senaPagada = ref(false)
const senaImporte = ref('')
function importeVisible(centavos) {
  if (centavos == null) return ''
  const valor = String(centavos).padStart(3, '0')
  return `${valor.slice(0, -2)}.${valor.slice(-2)}`
}
function importeEnCentavos(valor) {
  if (!valor.trim()) return null
  const partes = valor.trim().replace(',', '.').match(/^(\d+)(?:\.(\d{1,2}))?$/)
  if (!partes) throw new Error('Ingresá un importe válido con hasta dos decimales.')
  const centavos = (partes[1] + (partes[2] ?? '').padEnd(2, '0')).replace(/^0+(?=\d)/, '')
  if (centavos.length > 18) throw new Error('El importe de la seña es demasiado grande.')
  return centavos
}
const espacio = ref('')
const equipo = ref([])
const espacioElegido = computed(() => datos.value?.espacios.find(item => item.id === espacio.value))
const varias = ref(false)
const preparacion = computed(() => {
  const ajustes = datos.value?.preparacion
  return ajustes?.modo === 'general' ? ajustes.minutos : (ajustes?.servicios?.[servicio.value] ?? ajustes?.minutos ?? 0)
})
const servicioElegido = computed(() => datos.value?.servicios.find(item => item.id === servicio.value))
function servicioCompleto(item) {
  const ajustes = datos.value?.preparacion
  return item.duracion_minima != null && item.profesionales_requeridos != null && ajustes != null
}

async function api(ruta, method = 'GET', cuerpo) {
  const respuesta = await fetch(ruta, {
    method,
    headers: method === 'GET' ? {} : { 'Content-Type': 'application/json', 'X-Zenli-CSRF': csrf.value },
    body: cuerpo === undefined ? undefined : JSON.stringify(cuerpo),
  })
  if (respuesta.status === 401) {
    window.location.replace('/admin')
    throw new Error('Ingresá nuevamente para ver la agenda.')
  }
  const contenido = await respuesta.json().catch(() => null)
  if (!respuesta.ok || !contenido) throw new Error(contenido?.error || 'No se pudo completar la operación.')
  return contenido
}

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

async function cargarDia() {
  const { inicio, fin } = periodo.value
  datos.value = await api(`/api/admin/agenda?fecha=${claveDia(inicio)}&hasta=${claveDia(fin)}`)
  if (datos.value.espacios.length === 1) espacio.value = datos.value.espacios[0].id
  if (datos.value.servicios.length === 1) servicio.value = datos.value.servicios[0].id
  if (!usuario.value.admin && !editando.value) equipo.value = [usuario.value.profesional_id]
}

function cambiarEquipo() {
  equipo.value = equipo.value.slice(0, 1)
}

function abrirModal() {
  dialogo.value.showModal()
  modalAbierto.value = true
}

function abrirNuevoBloqueo() {
  bloqueoNuevo.value = {
    profesional: usuario.value.profesional_id ?? (datos.value.profesionales.length === 1 ? datos.value.profesionales[0].id : ''),
    fecha: fecha.value, todo_el_dia: false, hora_inicio: '', hora_fin: '',
  }
  error.value = ''
  dialogoBloqueo.value.showModal()
  modoBloqueo.value = true
}

function guardarBloqueo() {
  return ejecutar(async () => {
    const { profesional, ...bloqueo } = bloqueoNuevo.value
    await api(`/api/admin/agenda/profesionales/${profesional}/bloqueos`, 'POST', bloqueo)
    fecha.value = bloqueo.fecha
    dialogoBloqueo.value.close()
    await cargarDia()
    aviso.value = 'Horario no disponible guardado.'
  })
}

function abrirNuevoTurno() {
  limpiarTurno()
  fechaTurno.value = fecha.value
  abrirModal()
}

function editarTurno(turno) {
  editando.value = turno
  const inicio = new Date(turno.inicio)
  fechaTurno.value = calendario.format(inicio)
  hora.value = reloj.format(inicio)
  cliente.value = turno.cliente
  servicio.value = turno.servicio_id ?? ''
  duracion.value = Number(turno.duracion)
  senaPagada.value = turno.sena_pagada
  senaImporte.value = importeVisible(turno.sena_importe_centavos)
  espacio.value = turno.espacio_id
  equipo.value = [...turno.equipo]
  varias.value = equipo.value.length > 1
  abrirModal()
}

function limpiarTurno() {
  editando.value = null
  cliente.value = ''
  hora.value = ''
  duracion.value = ''
  senaPagada.value = false
  senaImporte.value = ''
  varias.value = false
  equipo.value = usuario.value.profesional_id ? [usuario.value.profesional_id] : []
  espacio.value = datos.value.espacios.length === 1 ? datos.value.espacios[0].id : ''
}

function guardarTurno() {
  return ejecutar(async () => {
    const anterior = editando.value
    await api(anterior ? `/api/admin/agenda/turnos/${anterior.id}` : '/api/admin/agenda/turnos', anterior ? 'PUT' : 'POST', {
      cliente: cliente.value,
      servicio: servicio.value,
      espacio: espacio.value,
      profesionales: equipo.value,
      inicio: `${fechaTurno.value}T${hora.value}`,
      duracion: duracion.value,
      sena_pagada: senaPagada.value,
      sena_importe_centavos: importeEnCentavos(senaImporte.value),
      revision: anterior ? Number(anterior.revision) : undefined,
    })
    fecha.value = fechaTurno.value
    dialogo.value.close()
    limpiarTurno()
    await cargarDia()
    aviso.value = anterior ? 'Turno actualizado.' : 'Turno guardado.'
  })
}

function cancelarTurno(turno) {
  if (!window.confirm('¿Cancelar este turno y liberar sus horarios?')) return
  return ejecutar(async () => {
    await api(`/api/admin/agenda/turnos/${turno.id}`, 'DELETE', { revision: Number(turno.revision) })
    if (editando.value?.id === turno.id) limpiarTurno()
    await cargarDia()
    aviso.value = 'Turno cancelado. Se liberaron sus horarios.'
  })
}

function fechaHoraLocal(valor) {
  const fechaHora = new Date(valor)
  return `${calendario.format(fechaHora)}T${reloj.format(fechaHora)}`
}

function editarPreparacion(item) {
  error.value = ''
  preparacionEditada.value = item
  inicioPreparacion.value = fechaHoraLocal(item.inicio)
  finPreparacion.value = fechaHoraLocal(item.fin)
  dialogoPreparacion.value.showModal()
}

function guardarPreparacion() {
  return ejecutar(async () => {
    const item = preparacionEditada.value
    await api(`/api/admin/agenda/preparaciones/${item.preparacionId}`, 'PUT', {
      inicio: inicioPreparacion.value, fin: finPreparacion.value, revision: Number(item.revision),
    })
    dialogoPreparacion.value.close()
    await cargarDia()
    aviso.value = 'Preparación actualizada.'
  })
}

function eliminarPreparacion(item) {
  if (!window.confirm('¿Eliminar esta preparación del espacio?')) return
  return ejecutar(async () => {
    await api(`/api/admin/agenda/preparaciones/${item.preparacionId}`, 'DELETE', { revision: Number(item.revision) })
    await cargarDia()
    aviso.value = 'Preparación eliminada.'
  })
}

function enviarMensaje() {
  return ejecutar(async () => {
    bandeja.value = await api('/api/admin/agenda/mensajes', 'POST', { destinatario: destinatario.value, texto: texto.value })
    texto.value = ''
    dialogoMensaje.value.close()
    aviso.value = 'Mensaje enviado.'
  })
}

function abrirMensaje() {
  error.value = ''
  aviso.value = ''
  mensajeAbierto.value = true
  dialogoMensaje.value.showModal()
}

function cerrarMensaje() {
  mensajeAbierto.value = false
  destinatario.value = ''
  texto.value = ''
  error.value = ''
}

function salir() {
  return ejecutar(async () => {
    await api('/api/admin/sesion', 'DELETE')
    window.location.replace('/admin')
  })
}

async function abrirHistorialTurno(turnoId) {
  if (!usuario.value?.admin) return
  errorHistorial.value = ''
  historialTurno.value = null
  cargandoHistorial.value = true
  await nextTick()
  dialogoHistorial.value?.showModal()
  try {
    historialTurno.value = await api(`/api/admin/auditoria/turnos/${turnoId}`)
  } catch (fallo) {
    errorHistorial.value = fallo.message
  } finally {
    cargandoHistorial.value = false
  }
}

function cerrarHistorialTurno() {
  if (dialogoHistorial.value?.open) dialogoHistorial.value.close()
  historialTurno.value = null
  errorHistorial.value = ''
}

async function abrirHistorialPeriodo() {
  if (!usuario.value?.admin) return
  errorPeriodo.value = ''
  historialPeriodo.value = null
  cargandoPeriodo.value = true
  await nextTick()
  dialogoHistorialPeriodo.value?.showModal()
  try {
    const { inicio, fin } = periodo.value
    historialPeriodo.value = await api(`/api/admin/auditoria/turnos?por=turno&desde=${claveDia(inicio)}&hasta=${claveDia(fin)}`)
  } catch (fallo) {
    errorPeriodo.value = fallo.message
  } finally {
    cargandoPeriodo.value = false
  }
}

function cerrarHistorialPeriodo() {
  if (dialogoHistorialPeriodo.value?.open) dialogoHistorialPeriodo.value.close()
  historialPeriodo.value = null
  errorPeriodo.value = ''
}

function verHistorialDesdePeriodo(turnoId) {
  cerrarHistorialPeriodo()
  abrirHistorialTurno(turnoId)
}

function claseBadgeHistorial(evento) {
  if (evento.tipo === 'CANCELADO') return 'cancelado'
  if (evento.cambio_sena) return 'sena'
  if (evento.tipo === 'CREADO') return 'creado'
  if (evento.tipo === 'PREP_EDITADA' || evento.tipo === 'PREP_ELIMINADA') return 'preparacion'
  return 'editado'
}

onMounted(() => ejecutar(async () => {
  document.title = 'Agenda · Zenli'
  window.addEventListener('hashchange', actualizarSeccion)
  const sesion = await api('/api/admin/sesion')
  if (!sesion.usuario) {
    window.location.replace('/admin')
    return
  }
  usuario.value = sesion.usuario
  csrf.value = sesion.csrf
  await Promise.all([cargarDia(), api('/api/admin/agenda/mensajes').then(contenido => { bandeja.value = contenido })])
}))
</script>

<template>
  <div class="agenda">
    <header>
      <span>Zenli</span>
      <button v-if="usuario" type="button" :disabled="ocupado" @click="salir">Cerrar sesión</button>
    </header>
    <LayoutAdministracion :usuario="usuario" :seccion="seccionAgenda">
    <main id="turnos">
      <h1>{{ seccionAgenda === 'mensajes' ? 'Mensajes privados' : 'Agenda interna' }}</h1>
      <p v-if="error && !modalAbierto && !modoBloqueo && !mensajeAbierto && !preparacionEditada" role="alert">{{ error }}</p>
      <p v-if="aviso" role="status">{{ aviso }}</p>
      <p v-if="!datos && ocupado" role="status">Cargando agenda…</p>

      <template v-if="datos">
        <section v-show="seccionAgenda === 'agenda'" class="panel">
          <div class="barra cabecera-calendario">
            <div class="acciones" aria-label="Vista de calendario">
              <button v-for="opcion in vistas" :key="opcion.id" type="button" :aria-pressed="vista === opcion.id"
                :class="{ secundario: vista !== opcion.id }" :disabled="ocupado" @click="cambiarVista(opcion.id)">{{ opcion.nombre }}</button>
            </div>
            <div class="acciones">
              <button v-if="usuario.admin" type="button" class="secundario" :disabled="ocupado" @click="abrirHistorialPeriodo">Historial y cancelados</button>
              <button v-if="usuario.admin" type="button" class="secundario" :disabled="ocupado || !datos.profesionales.length" @click="abrirNuevoBloqueo">+ Horario no disponible de profesional</button>
              <button type="button" class="nuevo-turno" :disabled="ocupado" @click="abrirNuevoTurno">+ Nuevo turno</button>
            </div>
          </div>
          <div class="barra controles-calendario">
            <div class="acciones">
              <button type="button" class="secundario" aria-label="Período anterior" :disabled="ocupado" @click="navegar(-1)">‹</button>
              <button type="button" class="secundario" :disabled="ocupado" @click="cambiarFecha(calendario.format(new Date()))">Hoy</button>
              <button type="button" class="secundario" aria-label="Período siguiente" :disabled="ocupado" @click="navegar(1)">›</button>
            </div>
            <h2 class="periodo">{{ encabezadoPeriodo }}</h2>
            <label>Ir a fecha <input :value="fecha" type="date" :disabled="ocupado" @change="cambiarFecha($event.target.value)"></label>
          </div>
          <label v-if="usuario.admin && usuario.profesional_id" class="opcion">
            <input v-model="soloMios" type="checkbox"> Mostrar solo mis turnos
          </label>
          <div v-if="vista !== 'dia'" class="calendario" :class="{ semanal: vista === 'semana' }">
            <template v-if="vista === 'mes'">
              <div v-for="nombre in ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom']" :key="nombre" class="dia-semana">{{ nombre }}</div>
              <span v-for="n in offsetMes" :key="`vacio-${n}`" aria-hidden="true"></span>
            </template>
            <button v-for="dia in dias" :key="dia.clave" type="button" class="celda"
              :class="{ propia: dia.propios }" :aria-pressed="fecha === dia.clave" :disabled="ocupado" @click="fecha = dia.clave">
              <strong><span v-if="vista === 'semana'">{{ dia.nombre }} </span>{{ dia.numero }}</strong>
              <small v-if="dia.citas">{{ dia.citas }} {{ dia.citas === 1 ? 'turno' : 'turnos' }}</small>
              <small v-if="dia.bloqueos" class="marca-bloqueo">{{ dia.bloqueos }} {{ dia.bloqueos === 1 ? 'horario no disponible' : 'horarios no disponibles' }}</small>
              <small v-if="dia.propios" class="mio">{{ dia.propios }} {{ dia.propios === 1 ? 'propio' : 'propios' }}</small>
              <span v-if="vista === 'semana'" class="resumen-dia">
                <small v-for="turno in dia.turnos.slice(0, 3)" :key="turno.id">
                  {{ turno.todo_el_dia ? 'Todo el día' : reloj.format(new Date(turno.inicio)) }} · {{ turno.bloqueado ? `No disponible · ${turno.profesionales}` : turno.servicio }}{{ !turno.bloqueado && turno.equipo_incompleto ? ' ⚠ Sin equipo completo' : '' }}
                </small>
              </span>
            </button>
          </div>
          <h2 class="detalle-dia">Turnos del {{ etiquetaDia.format(diaUtc(fecha)) }}</h2>
          <p v-if="!turnosDia.length">No hay turnos ni horarios no disponibles para este día.</p>
          <ul v-else class="turnos">
            <li v-for="turno in turnosDia" :key="turno.id" :class="{ propio: !turno.bloqueado && esPropio(turno), bloqueado: turno.bloqueado }">
              <strong>{{ horarioVisible(turno) }}</strong>
              <div class="detalle-turno">
                <span v-if="turno.bloqueado" class="marca-bloqueo">Profesional no disponible</span>
                <span v-else-if="turno.esPreparacion">Preparación del espacio</span>
                <span v-else>{{ turno.servicio }} · {{ turno.cliente }} <small v-if="esPropio(turno)" class="mio">Mi turno</small></span>
                <small v-if="turno.esPreparacion">{{ turno.espacio }}</small>
                <small v-else><template v-if="!turno.bloqueado && datos.espacios.length > 1">{{ turno.espacio }} · </template>{{ turno.profesionales }}</small>
                <small v-if="!turno.bloqueado && turno.equipo_incompleto" class="marca-bloqueo">⚠ Faltan profesionales por asignar</small>
                <small v-if="!turno.bloqueado && !turno.esPreparacion">Seña: {{ turno.sena_pagada ? 'pagada' : 'no pagada' }}<template v-if="turno.sena_importe_centavos != null"> · $ {{ importeVisible(turno.sena_importe_centavos) }}</template></small>
              </div>
              <a v-if="turno.bloqueado && usuario.admin" class="enlace-accion" :href="`/admin?profesional=${turno.profesional_id}#horarios-profesionales`">Editar horario no disponible</a>
              <div v-else-if="turno.esPreparacion && turno.editable" class="acciones">
                <button type="button" :disabled="ocupado" @click="editarPreparacion(turno)">Editar</button>
                <button type="button" class="secundario" :disabled="ocupado" @click="eliminarPreparacion(turno)">Eliminar</button>
              </div>
              <div v-else-if="turno.editable" class="acciones">
                <button v-if="usuario.admin" type="button" class="secundario" :disabled="ocupado" @click="abrirHistorialTurno(turno.id)">Historial</button>
                <button type="button" :disabled="ocupado" @click="editarTurno(turno)">Editar</button>
                <button type="button" class="secundario" :disabled="ocupado" @click="cancelarTurno(turno)">Cancelar turno</button>
              </div>
              <small v-else-if="!turno.bloqueado && !turno.esPreparacion">Turno compartido · lo administra el equipo de administración</small>
            </li>
          </ul>
        </section>

        <dialog ref="dialogoPreparacion" aria-labelledby="titulo-preparacion" @close="preparacionEditada = null" @cancel="ocupado && $event.preventDefault()">
          <div class="barra">
            <h2 id="titulo-preparacion">Editar preparación del espacio</h2>
            <button type="button" class="secundario" :disabled="ocupado" @click="dialogoPreparacion.close()">Cerrar</button>
          </div>
          <p v-if="error && preparacionEditada" role="alert">{{ error }}</p>
          <form @submit.prevent="guardarPreparacion">
            <label>Desde <input v-model="inicioPreparacion" type="datetime-local" required></label>
            <label>Hasta <input v-model="finPreparacion" type="datetime-local" required></label>
            <button :disabled="ocupado">Guardar cambios</button>
          </form>
        </dialog>

        <dialog ref="dialogoBloqueo" aria-labelledby="titulo-bloqueo" @close="modoBloqueo = false" @cancel="ocupado && $event.preventDefault()">
          <div class="barra">
            <h2 id="titulo-bloqueo">Horario no disponible de una profesional</h2>
            <button type="button" class="secundario" :disabled="ocupado" @click="dialogoBloqueo.close()">Cerrar</button>
          </div>
          <p v-if="error && modoBloqueo" role="alert">{{ error }}</p>
          <p>Este horario solo afecta a la profesional elegida; no reserva ni bloquea el espacio.</p>
          <form @submit.prevent="guardarBloqueo">
            <label>Profesional
              <select v-model="bloqueoNuevo.profesional" required>
                <option disabled value="">Elegí una profesional</option>
                <option v-for="persona in datos.profesionales" :key="persona.id" :value="persona.id">{{ persona.nombre }}</option>
              </select>
            </label>
            <label>Fecha <input v-model="bloqueoNuevo.fecha" type="date" required></label>
            <label class="opcion"><input v-model="bloqueoNuevo.todo_el_dia" type="checkbox"> Todo el día</label>
            <div v-if="!bloqueoNuevo.todo_el_dia" class="acciones">
              <label>Desde (24 h) <EntradaHora v-model="bloqueoNuevo.hora_inicio" required /></label>
              <label>Hasta (24 h) <EntradaHora v-model="bloqueoNuevo.hora_fin" permitir-medianoche required /></label>
            </div>
            <button :disabled="ocupado">Guardar horario no disponible</button>
          </form>
        </dialog>

        <dialog ref="dialogo" aria-labelledby="titulo-turno" @close="modalAbierto = false" @cancel="ocupado && $event.preventDefault()">
          <div class="barra">
            <h2 id="titulo-turno">{{ editando ? 'Editar turno' : 'Nuevo turno' }}</h2>
            <button type="button" class="secundario" :disabled="ocupado" @click="dialogo.close()">Cerrar</button>
          </div>
          <p v-if="error && modalAbierto" role="alert">{{ error }}</p>
            <p v-if="!datos.servicios.length">Creá un servicio en <a href="/admin">Administración</a> para cargar turnos.</p>
            <p v-else-if="!datos.espacios.length">Hace falta al menos un espacio activo.</p>
            <form v-else @submit.prevent="guardarTurno">
              <label>Servicio
                <select v-model="servicio" required>
                  <option disabled value="">Elegí un servicio</option>
                  <option v-for="item in datos.servicios" :key="item.id" :value="item.id">{{ item.nombre }}{{ servicioCompleto(item) ? '' : ' · configuración incompleta' }}</option>
                </select>
              </label>
              <p v-if="servicio">Preparación del espacio después del turno: {{ preparacion }} min.</p>
              <p v-if="servicioElegido && !servicioCompleto(servicioElegido)">Falta configurar {{ [servicioElegido.duracion_minima == null ? 'la duración informativa' : '', servicioElegido.profesionales_requeridos == null ? 'la cantidad de profesionales' : '', !datos.preparacion ? 'la preparación general' : ''].filter(Boolean).join(', ') }}. Podés guardar el turno igualmente.</p>
              <label>Fecha del turno <input v-model="fechaTurno" type="date" required></label>
              <label>Nombre del cliente <input v-model="cliente" maxlength="150" required></label>
              <label class="opcion"><input v-model="senaPagada" type="checkbox"> Seña pagada</label>
              <label>Importe de la seña (opcional) <input v-model.trim="senaImporte" type="text" inputmode="decimal" placeholder="0,00"></label>
              <div class="barra">
                <label>Hora (24 horas) <EntradaHora v-model="hora" required /></label>
                <label>Duración (min)
                  <input v-model.number="duracion" type="number" min="1" max="1440" step="1" list="duraciones-AgendaAdministrativa" required>
                </label>
                <datalist id="duraciones-AgendaAdministrativa">
                  <option v-for="minutos in [20, 30, 45, 60, 90]" :key="minutos" :value="minutos"></option>
                </datalist>
              </div>
              <label v-if="datos.espacios.length > 1">Espacio
                <select v-model="espacio" required>
                  <option disabled value="">Elegí un espacio</option>
                  <option v-for="item in datos.espacios" :key="item.id" :value="item.id">{{ item.nombre }}</option>
                </select>
              </label>
              <small v-if="datos.espacios.length > 1 && espacioElegido?.detalle">{{ espacioElegido.detalle }}</small>
              <label v-if="usuario.admin && datos.profesionales.length > 1" class="opcion"><input v-model="varias" type="checkbox" @change="cambiarEquipo"> Participa más de una profesional</label>
              <fieldset v-if="varias">
                <legend>Profesionales</legend>
                <label v-for="persona in datos.profesionales" :key="persona.id" class="opcion">
                  <input v-model="equipo" type="checkbox" :value="persona.id"> {{ persona.nombre }}
                </label>
              </fieldset>
              <label v-else-if="usuario.admin">Profesional
                <select :value="equipo[0] ?? ''" @change="equipo = $event.target.value ? [$event.target.value] : []">
                  <option value="">Sin asignar</option>
                  <option v-for="persona in datos.profesionales" :key="persona.id" :value="persona.id">{{ persona.nombre }}</option>
                </select>
              </label>
              <p v-if="servicioElegido && equipo.length < Number(servicioElegido.profesionales_requeridos)">Faltan profesionales: el turno quedará señalado en la agenda.</p>
              <button :disabled="ocupado || !fechaTurno || !servicioElegido">{{ editando ? 'Guardar cambios' : 'Cargar turno' }}</button>
            </form>
        </dialog>

        <dialog v-if="usuario.admin" ref="dialogoHistorial" aria-labelledby="titulo-historial-turno" @close="cerrarHistorialTurno">
          <div class="barra">
            <h2 id="titulo-historial-turno">Historial del turno</h2>
            <button type="button" class="secundario" @click="cerrarHistorialTurno">Cerrar</button>
          </div>
          <p v-if="cargandoHistorial" role="status">Cargando historial…</p>
          <p v-if="errorHistorial" role="alert">{{ errorHistorial }}</p>
          <template v-if="historialTurno">
            <p class="resumen-turno-modal">
              <strong>{{ historialTurno.turno.cliente }}</strong> · {{ historialTurno.turno.servicio }} ·
              {{ formatearRangoTurno(historialTurno.turno.inicio, historialTurno.turno.fin) }}
              <template v-if="historialTurno.turno.profesionales"> · {{ historialTurno.turno.profesionales }}</template>
            </p>
            <p class="resumen-turno-modal">
              <span class="badge-historial" :class="historialTurno.turno.cancelado ? 'cancelado' : 'creado'">
                {{ historialTurno.turno.cancelado ? 'Cancelado' : 'Activo' }}
              </span>
              Seña: {{ historialTurno.turno.sena_pagada ? 'pagada' : 'no pagada' }}
              <template v-if="historialTurno.turno.sena_importe_centavos != null"> · $ {{ importePesos(historialTurno.turno.sena_importe_centavos) }}</template>
            </p>
            <ol class="linea-historial">
              <li v-for="ev in historialTurno.eventos" :key="ev.id">
                <div class="fila-evento">
                  <span class="badge-historial" :class="claseBadgeHistorial(ev)">{{ ev.etiqueta }}</span>
                  <strong>{{ ev.resumen }}</strong>
                </div>
                <small>Por <strong>{{ ev.autor || 'Sin dato' }}</strong> · {{ formatearFechaHora(ev.fecha) }}</small>
                <ul v-if="ev.cambios?.length" class="cambios-historial">
                  <li v-for="(cambio, indice) in ev.cambios" :key="indice">{{ cambio }}</li>
                </ul>
              </li>
            </ol>
          </template>
        </dialog>

        <dialog v-if="usuario.admin" ref="dialogoHistorialPeriodo" aria-labelledby="titulo-historial-periodo" @close="cerrarHistorialPeriodo">
          <div class="barra">
            <h2 id="titulo-historial-periodo">Historial y cancelados · {{ encabezadoPeriodo }}</h2>
            <button type="button" class="secundario" @click="cerrarHistorialPeriodo">Cerrar</button>
          </div>
          <p v-if="cargandoPeriodo" role="status">Cargando historial del período…</p>
          <p v-if="errorPeriodo" role="alert">{{ errorPeriodo }}</p>
          <template v-if="historialPeriodo">
            <h3>Turnos cancelados del período ({{ historialPeriodo.cancelados.length }})</h3>
            <p v-if="!historialPeriodo.cancelados.length">No hay turnos cancelados en este período.</p>
            <ul v-else class="lista-periodo">
              <li v-for="item in historialPeriodo.cancelados" :key="item.id">
                <div>
                  <strong>{{ item.cliente }} · {{ item.servicio }}</strong>
                  <small>{{ formatearRangoTurno(item.inicio, item.fin) }}<template v-if="item.profesionales"> · {{ item.profesionales }}</template></small>
                  <small>Cancelado<template v-if="item.cancelado_por"> por <strong>{{ item.cancelado_por }}</strong></template><template v-if="item.cancelado_en"> el {{ formatearFechaHora(item.cancelado_en) }}</template></small>
                  <small>Seña: {{ item.sena_pagada ? 'pagada' : 'no pagada' }}<template v-if="item.sena_importe_centavos != null"> · $ {{ importePesos(item.sena_importe_centavos) }}</template></small>
                </div>
                <button type="button" class="secundario" @click="verHistorialDesdePeriodo(item.id)">Ver historial</button>
              </li>
            </ul>
            <h3>Actividad de turnos del período ({{ historialPeriodo.eventos.length }})</h3>
            <p v-if="!historialPeriodo.eventos.length">No hay movimientos registrados para turnos de este período.</p>
            <ul v-else class="lista-periodo">
              <li v-for="ev in historialPeriodo.eventos" :key="ev.id">
                <div>
                  <div class="fila-evento">
                    <span class="badge-historial" :class="claseBadgeHistorial(ev)">{{ ev.etiqueta }}</span>
                    <strong>{{ ev.resumen }}</strong>
                  </div>
                  <small>Por <strong>{{ ev.autor || 'Sin dato' }}</strong> · {{ formatearFechaHora(ev.fecha) }}</small>
                  <small>{{ ev.turno.cliente }} · {{ ev.turno.servicio }} · {{ formatearRangoTurno(ev.turno.inicio, ev.turno.fin) }}</small>
                </div>
                <button type="button" class="secundario" @click="verHistorialDesdePeriodo(ev.turno.id)">Ver turno</button>
              </li>
            </ul>
            <div class="barra pie-historial">
              <a class="enlace-accion" href="/admin#auditoria">Ver auditoría completa ↗</a>
            </div>
          </template>
        </dialog>

        <section v-show="seccionAgenda === 'mensajes'" id="mensajes" class="panel" aria-labelledby="titulo-mensajes">
            <div class="barra">
              <h2 id="titulo-mensajes">Bandeja de mensajes</h2>
              <div class="acciones">
                <button type="button" class="secundario" :disabled="ocupado" @click="ejecutar(async () => { bandeja = await api('/api/admin/agenda/mensajes') })">Actualizar</button>
                <button type="button" :disabled="ocupado || !bandeja.contactos.length" @click="abrirMensaje">+ Nuevo mensaje</button>
              </div>
            </div>
            <p v-if="!bandeja.contactos.length">No hay contactos disponibles para enviar mensajes.</p>
            <dialog ref="dialogoMensaje" aria-labelledby="titulo-nuevo-mensaje" @close="cerrarMensaje" @cancel="ocupado && $event.preventDefault()">
              <div class="barra"><h2 id="titulo-nuevo-mensaje">Nuevo mensaje</h2><button type="button" class="secundario" :disabled="ocupado" @click="dialogoMensaje.close()">Cerrar</button></div>
              <p v-if="error && mensajeAbierto" role="alert">{{ error }}</p>
            <form @submit.prevent="enviarMensaje">
              <label>Para
                <select v-model="destinatario" required>
                  <option disabled value="">Elegí un contacto</option>
                  <option v-for="contacto in bandeja.contactos" :key="contacto.id" :value="contacto.id">{{ contacto.nombre }}</option>
                </select>
              </label>
              <label>Mensaje <textarea v-model="texto" maxlength="2000" rows="3" required></textarea></label>
              <button :disabled="ocupado">Enviar mensaje</button>
            </form>
            </dialog>
            <p v-if="!bandeja.mensajes.length">Todavía no hay mensajes.</p>
            <ul v-else class="mensajes">
              <li v-for="mensaje in bandeja.mensajes" :key="mensaje.id">
                <strong>{{ Number(mensaje.enviado) ? 'Para' : 'De' }} {{ mensaje.contacto }}</strong>
                <p>{{ mensaje.texto }}</p>
              </li>
            </ul>
        </section>
      </template>
    </main>
    </LayoutAdministracion>
  </div>
</template>

<style scoped>
.agenda { min-height: 100vh; background: #f8f3e7; color: #15372a; }
header { display: flex; justify-content: space-between; padding: 1rem max(1rem, (100% - 70rem) / 2); background: #08271c; color: #f4dfb2; }
main { padding-block: clamp(1.25rem, 4vw, 2.6rem); }
h1, h2 { font-family: Georgia, serif; }
h1 { margin-top: 0; }
h2, p { margin-block: 0 .6rem; }
.panel { padding: 1rem; background: #fffaf1; border: 1px solid #e4dac3; border-radius: .9rem; margin-bottom: 1rem; }
.barra { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; justify-content: space-between; }
form, label { display: grid; gap: .35rem; }
form { gap: .8rem; }
.opcion { display: flex; gap: .5rem; align-items: center; margin-block: .3rem; }
fieldset { border: 1px solid #e4dac3; border-radius: .4rem; min-width: 0; }
input, select, textarea, button { font: inherit; }
input:not([type=checkbox], [type=radio]), select, textarea { min-width: 0; padding: .5rem; border: 1px solid #a4ad9f; border-radius: .35rem; background: #fffdf7; }
button { width: fit-content; padding: .6rem .8rem; border: 1px solid #214d3a; border-radius: .4rem; background: #214d3a; color: white; }
button:disabled { opacity: .55; }
.turnos { list-style: none; padding: 0; margin: 0; }
.turnos li { display: grid; grid-template-columns: minmax(5rem, 7rem) minmax(0, 1fr) auto; align-items: start; overflow-wrap: anywhere; gap: .6rem; padding-block: .65rem; border-top: 1px solid #e4dac3; }
.detalle-turno { display: flex; flex-wrap: wrap; align-items: baseline; gap: .35rem 1rem; }
.enlace-accion { display: inline-block; padding: .6rem .8rem; border: 1px solid #214d3a; border-radius: .4rem; background: #fffaf1; color: #214d3a; text-decoration: none; }
.enlace-accion:hover { background: #e5eee4; }
.secundario { background: #fffaf1; color: #214d3a; }
.acciones { display: flex; flex-wrap: wrap; gap: .4rem; }
.mensajes { list-style: none; padding: 0; }
.mensajes li { padding-top: .5rem; border-top: 1px solid #e4dac3; }
.mensajes p { white-space: pre-wrap; overflow-wrap: anywhere; }
[role=alert] { background: #f9e3df; padding: .65rem; }
[role=status] { padding: .45rem; }
.cabecera-calendario, .controles-calendario { margin-bottom: .8rem; }
.periodo { margin: 0; text-transform: capitalize; }
.calendario { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: .3rem; margin-block: .8rem 1rem; }
.celda { display: grid; align-content: start; gap: .25rem; width: 100%; min-height: 4rem; padding: .4rem; text-align: left; color: #15372a; border-color: #ddd4bd; background: #fffdf7; overflow-wrap: anywhere; }
.celda[aria-pressed=true] { outline: 2px solid #214d3a; outline-offset: -2px; }
.celda.propia, .turnos li.propio { background: #e5f2e6; }
.turnos li.propio { border-left: 4px solid #286644; padding-left: .5rem; }
.dia-semana { text-align: center; font-size: .8rem; font-weight: 700; }
.resumen-dia { display: grid; gap: .2rem; }
.mio { display: inline-block; width: fit-content; border-radius: .25rem; padding: .1rem .25rem; background: #d2ead7; color: #134627; font-weight: 700; }
.turnos li.bloqueado { background: #f4e8d6; border-left: 4px solid #95642d; padding-left: .5rem; }
.marca-bloqueo { color: #61411b; font-weight: 700; }
.detalle-dia { margin-top: 1rem; }
dialog { max-height: 88dvh; width: min(calc(100% - 1.5rem), 38rem); padding: 1.2rem; border: 1px solid #e4dac3; border-radius: .8rem; background: #fffaf1; color: #15372a; }
dialog::backdrop { background: #071b13a8; }
dialog form { margin-top: .7rem; }
dialog h3 { margin: 1rem 0 .4rem; font-family: Georgia, serif; font-size: 1rem; }
.resumen-turno-modal { font-size: .9rem; margin-bottom: .4rem; }
.linea-historial, .lista-periodo { list-style: none; padding: 0; margin: .6rem 0; }
.linea-historial > li { display: grid; gap: .2rem; padding: .55rem 0 .55rem .75rem; border-left: 3px solid #d5c8aa; border-bottom: 1px solid #efe7d5; }
.lista-periodo > li { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: .6rem; padding: .6rem 0; border-bottom: 1px solid #e4dac3; }
.lista-periodo small { display: block; color: #526255; font-size: .8rem; }
.fila-evento { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem; }
.cambios-historial { margin: .2rem 0 0; padding-left: 1.1rem; font-size: .84rem; color: #2c4a3c; }
.badge-historial { display: inline-block; padding: .1rem .42rem; border-radius: .3rem; font-size: .72rem; font-weight: 700; }
.badge-historial.creado { background: #e4efdf; color: #194832; }
.badge-historial.sena { background: #f4e6c4; color: #6c460d; }
.badge-historial.cancelado { background: #f9e3df; color: #75271f; }
.badge-historial.editado { background: #e5ecf4; color: #1e4266; }
.badge-historial.preparacion { background: #efe7dc; color: #5e4422; }
.pie-historial { margin-top: 1rem; padding-top: .8rem; border-top: 1px solid #e4dac3; }
@media (max-width: 42rem) {
  .celda { font-size: .72rem; padding: .25rem; }
  .resumen-dia { display: none; }
}
@media (max-width: 42rem) {
  .turnos li { grid-template-columns: 1fr; }
}
</style>
