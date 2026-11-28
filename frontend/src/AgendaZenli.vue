<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import LayoutAdministracion from './LayoutAdministracion.vue'
import ClientesAgenda from './ClientesAgenda.vue'
import FichaClienteDialog from './FichaClienteDialog.vue'
import ClavesAccesoAgenda from './ClavesAccesoAgenda.vue'
import { admiteClavesAcceso } from './clavesAcceso.js'
import CalendarioHoras from './CalendarioHoras.vue'
import EntradaHora from './EntradaHora.vue'
import HorariosPersonales from './HorariosPersonales.vue'
import {
  avisarCambioCuenta, borrarAlta, borrarCopia, borrarCopiaDeCuenta, borrarDatosLocales, canalAgendaLocal,
  guardarAlta, guardarCopia, leerAltas, leerCopia,
} from './agendaSinConexion.js'
import { agendaInstalada, solicitarInstalacionAgenda } from './instalacionAgenda.js'
import { enlaceWhatsApp } from './formatoWhatsApp.js'
import lotoUrl from './image/loto.svg?no-inline'

const seccionAgenda = ref('agenda')
const fichaId = ref(null)
const esSeccionPersonal = computed(() => ['mis-horarios', 'horarios-no-disponibles'].includes(seccionAgenda.value))

function actualizarSeccion() {
  if (sinConexion.value) {
    fichaId.value = null
    seccionAgenda.value = 'agenda'
    return
  }
  const seccion = window.location.hash.slice(1)
  if (seccion !== 'clientes') fichaId.value = null
  if (seccion === 'mensajes' || seccion === 'clientes' || (usuario.value?.profesional_id && ['mis-horarios', 'horarios-no-disponibles'].includes(seccion))) {
    seccionAgenda.value = seccion
    return
  }
  seccionAgenda.value = 'agenda'
}

onBeforeUnmount(() => {
  window.removeEventListener('hashchange', actualizarSeccion)
  document.removeEventListener('pointerdown', cerrarMenuFuera)
  window.removeEventListener('online', reconectar)
  window.removeEventListener('offline', desconectar)
  window.removeEventListener('focus', alVolver)
  clearTimeout(vencimientoCopia)
  canalSesion?.close()
})

const zona = 'America/Argentina/Mendoza'
const fecha = ref(new Intl.DateTimeFormat('sv-SE', { timeZone: zona }).format(new Date()))
const calendario = new Intl.DateTimeFormat('sv-SE', { timeZone: zona })
const reloj = new Intl.DateTimeFormat('es-AR', { timeZone: zona, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
const datos = ref(null)
const sinConexion = ref(false)
const copia = ref(null)
const altas = ref([])
const altaEnRevision = ref(null)
const estadoCopia = ref('')
const dialogoBorrarCopia = ref(null)
const confirmandoBorrado = ref(false)
let generacionCopia = 0
let vencimientoCopia
let reconexionSolicitada = false
const canalSesion = canalAgendaLocal()
const vista = ref('semana')
const profesionalSeleccionada = ref('')
const menuPerfil = ref(null)
const clavesAccesoAgenda = ref(null)

function gestionarClavesAcceso() {
  cerrarMenuPerfil(true)
  clavesAccesoAgenda.value?.abrir()
}
const horarioHabitual = ref(null)
const dialogoInstalacion = ref(null)
const instalandoAgenda = ref(false)

async function descargarAgenda() {
  instalandoAgenda.value = true
  try {
    if (!await solicitarInstalacionAgenda() && !agendaInstalada.value) dialogoInstalacion.value?.showModal()
  } finally {
    instalandoAgenda.value = false
  }
}

const dialogoBusqueda = ref(null)
const buscandoTurnos = ref(false)
const nombreBuscado = ref('')
const resultadosTurnos = ref(null)
const dialogoAsistencia = ref(null)
const turnoAsistencia = ref(null)
const asistenciaNueva = ref('')
const estadosAsistencia = { programado: 'Programado', atendido: 'Atendido', ausente: 'Ausente' }

function abrirBusqueda() {
  error.value = ''
  buscandoTurnos.value = true
  dialogoBusqueda.value.showModal()
}

function cerrarBusqueda() {
  buscandoTurnos.value = false
  nombreBuscado.value = ''
  resultadosTurnos.value = null
  error.value = ''
}

function buscarTurnos() {
  return ejecutar(async () => {
    resultadosTurnos.value = null
    resultadosTurnos.value = await api('/api/admin/agenda/turnos/buscar', 'POST', { nombre: nombreBuscado.value })
  })
}

function mostrarFicha(id) {
  dialogoBusqueda.value?.close()
  fichaId.value = id
  window.location.hash = 'clientes'
}

function abrirResultado(turno) {
  return ejecutar(async () => {
    dialogoBusqueda.value?.close()
    if (seccionAgenda.value !== 'agenda') {
      window.location.hash = 'turnos'
      actualizarSeccion()
    }
    profesionalSeleccionada.value = ''
    vista.value = 'dia'
    fecha.value = calendario.format(new Date(turno.inicio))
    await Promise.all([cargarDia(), cargarHorarioEnVista()])
    if (datos.value?.turnos.some(item => item.id === turno.id)) await verEnAgenda(fecha.value, turno.id)
    else aviso.value = 'El turno ya no está disponible en esa fecha.'
  })
}

async function abrirAsistencia(turno) {
  error.value = ''
  turnoAsistencia.value = turno
  asistenciaNueva.value = turno.asistencia
  await nextTick()
  dialogoAsistencia.value.showModal()
}

function guardarAsistencia() {
  return ejecutar(async () => {
    const turno = turnoAsistencia.value
    await api(`/api/admin/agenda/turnos/${turno.id}/asistencia`, 'PUT', {
      asistencia: asistenciaNueva.value, revision: Number(turno.revision),
    })
    dialogoAsistencia.value.close()
    await cargarDia()
    await prepararCopia()
    aviso.value = 'Asistencia actualizada.'
  })
}

const dialogoDisponibilidad = ref(null)
const disponibilidadAbierta = ref(false)
const fechaDisponibilidad = ref('')
const profesionalDisponibilidad = ref('')
const disponibilidad = ref(null)
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
const bloqueoNuevo = ref({ profesional: '', fecha: '', todo_el_dia: false, hora_inicio: '', hora_fin: '' })
const dialogoHojaDia = ref(null)
const hojaDiaAbierta = ref(false)
const dialogoConfirmarWA = ref(null)
const turnoConfirmarWA = ref(null)

function abrirHojaDelDia() {
  hojaDiaAbierta.value = true
  nextTick(() => dialogoHojaDia.value?.showModal())
}

function imprimirHojaDelDia() {
  window.print()
}

function accionarWhatsApp(turno, evento) {
  if (turno?.cliente_no_contactar) {
    if (evento) evento.preventDefault()
    turnoConfirmarWA.value = turno
    nextTick(() => dialogoConfirmarWA.value?.showModal())
  }
}

function abrirWhatsAppConfirmado() {
  if (!turnoConfirmarWA.value) return
  const url = enlaceWhatsAppTurno(turnoConfirmarWA.value)
  dialogoConfirmarWA.value?.close()
  turnoConfirmarWA.value = null
  if (url) window.open(url, '_blank', 'noopener,noreferrer')
}
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

const perfilEnVista = computed(() => usuario.value?.admin
  ? profesionalSeleccionada.value : (usuario.value?.profesional_id ?? ''))
const profesionalEnVista = computed(() =>
  datos.value?.profesionales.find(persona => persona.id === perfilEnVista.value),
)
const tituloAgenda = computed(() => {
  if (!usuario.value?.admin || (perfilEnVista.value && perfilEnVista.value === usuario.value.profesional_id)) return 'Mi agenda'
  return profesionalEnVista.value ? `Agenda de ${profesionalEnVista.value.nombre}` : 'Agenda general'
})

async function cargarHorarioEnVista() {
  const perfil = perfilEnVista.value
  horarioHabitual.value = null
  if (sinConexion.value) {
    if (perfil === usuario.value?.profesional_id) horarioHabitual.value = copia.value?.horario ?? null
    return
  }
  if (!perfil) return
  try {
    const consulta = usuario.value.admin ? '?profesional=' + encodeURIComponent(perfil) : ''
    const contenido = await api('/api/admin/agenda/turnos/horario-habitual' + consulta)
    if (perfilEnVista.value === perfil) horarioHabitual.value = contenido.horario ?? null
  } catch {
    // La grilla sigue disponible cuando no se puede cargar el horario habitual.
  }
}

function cerrarMenuPerfil(devolverFoco = false) {
  if (!menuPerfil.value?.open) return
  menuPerfil.value.open = false
  if (devolverFoco) menuPerfil.value.querySelector('summary')?.focus()
}

function cerrarMenuFuera(evento) {
  if (!menuPerfil.value?.contains(evento.target)) cerrarMenuPerfil()
}

function elegirVistaAgenda(id) {
  profesionalSeleccionada.value = id
  cerrarMenuPerfil(true)
  cargarHorarioEnVista()
}

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
    .filter(turno => !perfilEnVista.value || turno.equipo.includes(perfilEnVista.value))
    .filter(turno => !sinConexion.value || (calendario.format(new Date(turno.inicio)) <= claveDia(periodo.value.fin)
      && calendario.format(new Date(new Date(turno.fin).getTime() - 1)) >= claveDia(periodo.value.inicio)))
    .sort((a, b) => new Date(a.inicio) - new Date(b.inicio))
})

function horarioVisible(turno) {
  if (turno.todo_el_dia) return 'Todo el día'
  const fin = reloj.format(new Date(turno.fin))
  return `${reloj.format(new Date(turno.inicio))}–${turno.bloqueado && fin === '00:00' ? '24:00' : fin}`
}

function enlaceWhatsAppTurno(turno) {
  if (!turno?.cliente_telefono) return null
  const horaTexto = reloj.format(new Date(turno.inicio))
  const diaTexto = new Intl.DateTimeFormat('es-AR', {
    weekday: 'long', day: 'numeric', month: 'long', timeZone: zona,
  }).format(diaUtc(turno.inicio.slice(0, 10)))
  const mensaje = `Hola ${turno.cliente}, te recordamos tu turno en Zenli para ${turno.servicio} el ${diaTexto} a las ${horaTexto} hs. ¡Te esperamos!`
  return enlaceWhatsApp(turno.cliente_telefono, mensaje)
}

function turnosEnDia(dia) {
  return turnosVisibles.value.filter(turno =>
    calendario.format(new Date(turno.inicio)) <= dia &&
    calendario.format(new Date(new Date(turno.fin).getTime() - 1)) >= dia,
  )
}

const diasSemana = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo']

function vigente(franja, fecha) {
  return franja.vigente_desde <= fecha && (!franja.vigente_hasta || franja.vigente_hasta >= fecha)
}

const franjasHabituales = computed(() => {
  const hoy = calendario.format(new Date())
  return (horarioHabitual.value?.franjas ?? []).filter(franja =>
    vigente(franja, hoy) && franja.vigente_desde !== franja.vigente_hasta,
  )
})

function minutosDeFranja(hora) {
  const [h, m, segundos = 0] = hora.split(':').map(Number)
  return h * 60 + m + segundos / 60
}

function franjasAtencion(clave, semana, turnos) {
  if (!perfilEnVista.value) return null
  if (turnos.some(turno => turno.bloqueado && turno.todo_el_dia)) return []
  const horario = horarioHabitual.value
  if (!horario) return null

  const especiales = horario.excepciones.filter(item => item.tipo === 'especial' && item.fecha === clave)
  const vigentes = horario.franjas.filter(franja => vigente(franja, clave))
  const franjas = especiales.length ? especiales : vigentes.filter(franja => Number(franja.dia_semana) === semana)
  const tieneHorarioSemanal = vigentes.some(franja => franja.vigente_desde !== franja.vigente_hasta)
  if (!especiales.length && !horario.por_franjas && ((!tieneHorarioSemanal && !franjas.length) || turnos.some(turno => !turno.bloqueado))) {
    return null
  }
  return franjas.map(franja => ({ inicio: minutosDeFranja(franja.hora_inicio), fin: minutosDeFranja(franja.hora_fin) }))
}

const feriados = computed(() => new Set(datos.value?.feriados ?? []))
const feriadoSeleccionado = computed(() => feriados.value.has(fecha.value))

const dias = computed(() => {
  const { inicio, fin } = periodo.value
  const resultado = []
  for (let dia = inicio; dia <= fin; dia = sumarDias(dia, 1)) {
    const clave = claveDia(dia)
    const turnos = turnosEnDia(clave)
    const bloqueos = turnos.filter(turno => turno.bloqueado).length
    const preparaciones = turnos.filter(turno => turno.esPreparacion).length
    const feriado = feriados.value.has(clave)
    resultado.push({
      clave, numero: dia.getUTCDate(), nombre: nombreDia.format(dia), turnos, bloqueos, feriado,
      franjasAtencion: feriado ? [] : franjasAtencion(clave, dia.getUTCDay() || 7, turnos),
      citas: turnos.length - bloqueos - preparaciones, propios: turnos.filter(turno => !turno.bloqueado && !turno.esPreparacion && esPropio(turno)).length,
    })
  }
  return resultado
})
const turnosDia = computed(() => turnosEnDia(fecha.value))

const turnosValidosHoja = computed(() => turnosDia.value.filter(t => !t.bloqueado && !t.esPreparacion))
const senasPagadasHoja = computed(() => turnosValidosHoja.value.filter(t => t.sena_pagada).length)
const nombreProfesionalEnVista = computed(() => {
  if (!perfilEnVista.value) return 'Todas las profesionales'
  return datos.value?.profesionales.find(p => p.id === perfilEnVista.value)?.nombre ?? 'Mi agenda'
})
const fechaFormateadaHoja = computed(() => {
  const dt = diaUtc(fecha.value)
  const semana = new Intl.DateTimeFormat('es-AR', { weekday: 'long', timeZone: 'UTC' }).format(dt)
  const dia = new Intl.DateTimeFormat('es-AR', { day: 'numeric', timeZone: 'UTC' }).format(dt)
  const mesAno = new Intl.DateTimeFormat('es-AR', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(dt)
  return {
    semana: semana.charAt(0).toUpperCase() + semana.slice(1),
    dia,
    mesAno: mesAno.charAt(0).toUpperCase() + mesAno.slice(1),
  }
})

function alternarFeriado() {
  const dia = fecha.value
  const activo = !feriadoSeleccionado.value
  return ejecutar(async () => {
    await api(`/api/admin/agenda/feriados/${dia}`, 'PUT', { activo })
    await cargarDia()
    aviso.value = activo ? 'Feriado marcado para todo el centro.' : 'Feriado quitado del calendario.'
  })
}
const resumen = computed(() => {
  const items = turnosVisibles.value
  return [
    { nombre: 'Turnos', total: items.filter(item => !item.bloqueado && !item.esPreparacion).length },
    { nombre: 'Horarios no disponibles', total: items.filter(item => item.bloqueado).length },
    { nombre: 'Preparaciones', total: items.filter(item => item.esPreparacion).length },
  ]
})
const identidad = computed(() =>
  datos.value?.profesionales.find(persona => persona.id === usuario.value?.profesional_id)?.nombre
    ?? usuario.value?.email ?? '',
)

async function verEnAgenda(dia, id) {
  fecha.value = dia
  await nextTick()
  const fila = document.getElementById('agenda-fila-' + id)
  fila?.focus({ preventScroll: true })
  fila?.scrollIntoView({ block: 'nearest' })
}

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
const clienteVinculado = ref(null)
const fichasCoincidentes = ref(null)
const nuevaFichaTurno = ref(null)

function buscarFichaParaTurno() {
  return ejecutar(async () => {
    fichasCoincidentes.value = await api('/api/admin/agenda/clientes/buscar', 'POST', { nombre: cliente.value })
  })
}

function seleccionarFichaParaTurno(ficha) {
  clienteVinculado.value = ficha.id
  cliente.value = ficha.nombre
  fichasCoincidentes.value = null
}

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
  if (sinConexion.value) throw new Error('Esta acción requiere conexión.')
  let respuesta
  try {
    respuesta = await fetch(ruta, {
      method,
      cache: 'no-store',
      headers: method === 'GET' ? {} : { 'Content-Type': 'application/json', 'X-Zenli-CSRF': csrf.value },
      body: cuerpo === undefined ? undefined : JSON.stringify(cuerpo),
    })
  } catch {
    throw Object.assign(new Error('No se pudo conectar con la agenda.'), { red: true })
  }
  if (respuesta.status === 401) {
    await borrarCopia().catch(() => {})
    window.location.replace('/admin')
  }
  const contenido = await respuesta.json().catch(() => null)
  if (!respuesta.ok || !contenido) {
    throw Object.assign(new Error(contenido?.error || 'No se pudo completar la operación.'), { estado: respuesta.status })
  }
  return contenido
}

function rutaAgenda(desde, hasta) {
  return `/api/admin/agenda?fecha=${desde}&hasta=${hasta}`
}

const periodoSinCopia = computed(() => sinConexion.value && copia.value
  && (claveDia(periodo.value.inicio) < copia.value.desde || claveDia(periodo.value.fin) > copia.value.hasta))
const rangoCopia = computed(() => copia.value
  ? `${copia.value.desde} al ${copia.value.hasta}`
  : '')

const ocupacionesDelDia = computed(() => {
  const muestra = copia.value
  const dia = fecha.value
  if (!muestra?.agenda.ocupaciones_espacios || dia < muestra.desde || dia > muestra.hasta) return null
  const espacios = new Map(muestra.agenda.espacios.map(espacio => [espacio.id, { ...espacio, tramos: [] }]))
  for (const tramo of muestra.agenda.ocupaciones_espacios) {
    const inicio = new Date(tramo.inicio)
    const fin = new Date(tramo.fin)
    const primerDia = calendario.format(inicio)
    const ultimoDia = calendario.format(new Date(fin.getTime() - 1))
    if (primerDia > dia || ultimoDia < dia) continue
    const desde = primerDia === dia ? reloj.format(inicio) : '00:00'
    const hasta = calendario.format(fin) === dia ? reloj.format(fin) : '24:00'
    espacios.get(tramo.espacio_id)?.tramos.push(`${desde}–${hasta}`)
  }
  return [...espacios.values()].filter(espacio => espacio.tramos.length)
})

// Comparamos horas de calendario de Mendoza. El campo del formulario es una hora local.
function instanteLocal(instante) {
  return Date.parse(`${calendario.format(instante)}T${reloj.format(instante)}Z`)
    + instante.getUTCSeconds() * 1000 + instante.getUTCMilliseconds()
}

const conflictoEspacioSinConexion = computed(() => {
  if (!sinConexion.value || !espacio.value || !fechaTurno.value || !hora.value) return false
  const inicio = Date.parse(`${fechaTurno.value}T${hora.value}Z`)
  const minutos = Number(duracion.value)
  if (!Number.isFinite(inicio) || !(minutos > 0)) return false
  const fin = inicio + (minutos + (Number(preparacion.value) || 0)) * 60_000
  return copia.value?.agenda.ocupaciones_espacios?.some(tramo =>
    tramo.espacio_id === espacio.value && instanteLocal(new Date(tramo.inicio)) < fin
      && instanteLocal(new Date(tramo.fin)) > inicio,
  ) ?? false
})

function confirmarBorradoCopia() {
  error.value = ''
  confirmandoBorrado.value = true
  dialogoBorrarCopia.value.showModal()
}

function quitarCopiaVisible() {
  ++generacionCopia
  copia.value = null
  if (sinConexion.value) {
    dialogo.value?.close()
    datos.value = null
    horarioHabitual.value = null
  }
  estadoCopia.value = 'Se borró la copia de la agenda. Los turnos pendientes siguen guardados.'
}

function borrarCacheAgenda() {
  return ejecutar(async () => {
    const cuenta = usuario.value.id
    await borrarCopiaDeCuenta(cuenta)
    quitarCopiaVisible()
    canalSesion?.postMessage({ tipo: 'copia-borrada', cuenta })
    dialogoBorrarCopia.value.close()
  })
}

async function prepararCopia() {
  const cuenta = usuario.value
  const generacion = generacionCopia
  const hoy = diaUtc(calendario.format(new Date()))
  const lunes = sumarDias(hoy, -((hoy.getUTCDay() + 6) % 7))
  const desde = claveDia(lunes)
  const hasta = claveDia(sumarDias(lunes, 30))
  try {
    const agenda = await api(rutaAgenda(desde, hasta) + '&ocupacion_espacios=1')
    const horario = cuenta.profesional_id
      ? (await api('/api/admin/agenda/turnos/horario-habitual')).horario
      : null
    agenda.turnos = agenda.turnos.map(({ sena_importe_centavos, sena_pagada, cliente_id, ...turno }) => ({
      ...turno,
      cliente: turno.cliente.trim().split(/\s+/).map(nombre => nombre[0] + '.').join(' '),
      editable: false,
    }))
    agenda.preparaciones = agenda.preparaciones.map(item => ({ ...item, editable: false }))
    if (usuario.value !== cuenta || sinConexion.value || generacion !== generacionCopia) return
    const instante = Date.now()
    const muestra = { usuario: { id: cuenta.id, admin: cuenta.admin, profesional_id: cuenta.profesional_id },
      agenda, horario, desde, hasta, instante, vence: instante + 60 * 60 * 1000 }
    await guardarCopia(muestra)
    if (usuario.value !== cuenta || sinConexion.value || generacion !== generacionCopia) return
    copia.value = muestra
    estadoCopia.value = ''
  } catch (fallo) {
    if (fallo.red) await mostrarCopia()
    else estadoCopia.value = 'No se pudo actualizar la copia para usar sin conexión.'
  }
}

async function mostrarCopia() {
  sinConexion.value = true
  clavesAccesoAgenda.value?.cerrar()
  nuevaFichaTurno.value?.cerrar()
  clienteVinculado.value = null
  fichasCoincidentes.value = null
  if (editando.value) {
    dialogo.value?.close()
    limpiarTurno()
  }
  for (const modal of [dialogoDisponibilidad, dialogoBloqueo, dialogoMensaje, dialogoPreparacion, dialogoBusqueda, dialogoAsistencia, dialogoHojaDia, dialogoConfirmarWA]) modal.value?.close()
  clearTimeout(vencimientoCopia)
  datos.value = null
  const muestra = await leerCopia().catch(() => null)
  if (!muestra || muestra.vence <= Date.now() || (usuario.value && muestra.usuario.id !== usuario.value.id)) {
    if (muestra?.vence <= Date.now()) await borrarCopia().catch(() => {})
    copia.value = null
    usuario.value = null
    altas.value = []
    estadoCopia.value = 'Conectate e iniciá sesión para descargar una copia de la agenda.'
    return
  }
  copia.value = muestra
  usuario.value = muestra.usuario
  altas.value = await leerAltas(muestra.usuario.id).catch(() => [])
  actualizarSeccion()
  estadoCopia.value = ''
  await cargarDia()
  await cargarHorarioEnVista()
  vencimientoCopia = setTimeout(mostrarCopia, muestra.vence - Date.now())
}

async function sincronizarAltas() {
  altas.value = await leerAltas(usuario.value.id).catch(() => [])
  let sincronizadas = 0
  for (const alta of altas.value) {
    if (alta.error) continue
    try {
      await api('/api/admin/agenda/turnos', 'POST', { ...alta.turno, operacion: alta.id })
    } catch (fallo) {
      if (fallo.red) throw fallo
      if (![400, 403, 409, 422].includes(fallo.estado)) throw fallo
      await guardarAlta({ ...alta, error: fallo.message })
      continue
    }
    await borrarAlta(alta.id)
    sincronizadas++
  }
  altas.value = await leerAltas(usuario.value.id).catch(() => [])
  if (sincronizadas) aviso.value = sincronizadas === 1 ? 'Se sincronizó un turno.' : `Se sincronizaron ${sincronizadas} turnos.`
}

async function cargarEnLinea() {
  sinConexion.value = false
  clearTimeout(vencimientoCopia)
  try {
    const sesion = await api('/api/admin/sesion')
    if (!sesion.usuario) {
      await borrarCopia().catch(() => {})
      window.location.replace('/admin')
      return
    }
    const anterior = await leerCopia().catch(() => null)
    if (anterior && (anterior.usuario.id !== sesion.usuario.id || anterior.usuario.admin !== sesion.usuario.admin
        || anterior.usuario.profesional_id !== sesion.usuario.profesional_id)) {
      await borrarCopia()
      copia.value = null
      datos.value = null
    }
    usuario.value = sesion.usuario
    csrf.value = sesion.csrf
    actualizarSeccion()
    await sincronizarAltas()
    await Promise.all([cargarDia(), cargarHorarioEnVista(), api('/api/admin/agenda/mensajes').then(contenido => { bandeja.value = contenido })])
    await prepararCopia()
  } catch (fallo) {
    if (!fallo.red) throw fallo
    await mostrarCopia()
  }
}

function reconectar() {
  if (ocupado.value) {
    reconexionSolicitada = true
    return
  }
  return ejecutar(cargarEnLinea)
}

function desconectar() {
  if (!ocupado.value) return ejecutar(mostrarCopia)
}

function alVolver() {
  if (navigator.onLine && (sinConexion.value || altas.value.some(alta => !alta.error))) return reconectar()
  if (sinConexion.value && copia.value?.vence <= Date.now()) return desconectar()
}

async function descartarAlta(alta) {
  if (!window.confirm('¿Descartar este turno pendiente del dispositivo?')) return
  await ejecutar(async () => {
    await borrarAlta(alta.id)
    altas.value = await leerAltas(usuario.value.id)
  })
}

function revisarAlta(alta) {
  const item = alta.turno
  limpiarTurno()
  altaEnRevision.value = alta
  const [dia, horario] = item.inicio.split('T')
  fechaTurno.value = dia
  hora.value = horario
  cliente.value = item.cliente
  servicio.value = item.servicio
  duracion.value = item.duracion
  espacio.value = item.espacio
  equipo.value = [...item.profesionales]
  varias.value = equipo.value.length > 1
  senaPagada.value = item.sena_pagada
  senaImporte.value = importeVisible(item.sena_importe_centavos)
  abrirModal()
}

async function ejecutar(accion) {
  if (ocupado.value) return
  ocupado.value = true
  error.value = ''
  aviso.value = ''
  try {
    await accion()
  } catch (fallo) {
    if (fallo.red) await mostrarCopia()
    else error.value = fallo.message
  } finally {
    ocupado.value = false
    if (reconexionSolicitada) {
      reconexionSolicitada = false
      if (navigator.onLine) void reconectar()
    } else if (!navigator.onLine && !sinConexion.value) void desconectar()
  }
}

async function cargarDia() {
  const { inicio, fin } = periodo.value
  if (sinConexion.value) {
    datos.value = copia.value?.agenda ?? null
  } else {
    datos.value = await api(rutaAgenda(claveDia(inicio), claveDia(fin)))
  }
  if (!datos.value) return
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

function abrirDisponibilidad() {
  fechaDisponibilidad.value = fecha.value
  profesionalDisponibilidad.value = perfilEnVista.value || usuario.value.profesional_id || ''
  disponibilidad.value = null
  error.value = ''
  dialogoDisponibilidad.value.showModal()
  disponibilidadAbierta.value = true
  if (profesionalDisponibilidad.value) consultarDisponibilidad()
}

function consultarDisponibilidad() {
  disponibilidad.value = null
  return ejecutar(async () => {
    const filtros = new URLSearchParams({ fecha: fechaDisponibilidad.value })
    if (usuario.value.admin) filtros.set('profesional', profesionalDisponibilidad.value)
    disponibilidad.value = await api(`/api/admin/agenda/turnos/disponibilidad?${filtros}`)
  })
}

function horaHueco(fin) {
  const instante = new Date(fin)
  return calendario.format(instante) > fechaDisponibilidad.value ? '24:00' : reloj.format(instante)
}

function abrirNuevoBloqueo() {
  bloqueoNuevo.value = {
    profesional: perfilEnVista.value || usuario.value.profesional_id || (datos.value.profesionales.length === 1 ? datos.value.profesionales[0].id : ''),
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
  clienteVinculado.value = turno.cliente_id ?? null
  fichasCoincidentes.value = null
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
  altaEnRevision.value = null
  cliente.value = ''
  clienteVinculado.value = null
  fichasCoincidentes.value = null
  hora.value = ''
  duracion.value = 20
  senaPagada.value = false
  senaImporte.value = ''
  varias.value = false
  const perfil = perfilEnVista.value || usuario.value.profesional_id
  equipo.value = perfil ? [perfil] : []
  espacio.value = datos.value.espacios.length === 1 ? datos.value.espacios[0].id : ''
}

function guardarTurno() {
  return ejecutar(async () => {
    const anterior = editando.value
    const turno = {
      cliente: cliente.value.trim(),
      ...(!sinConexion.value && (clienteVinculado.value || anterior?.cliente_id) ? { cliente_id: clienteVinculado.value } : {}),
      servicio: servicio.value,
      espacio: espacio.value,
      profesionales: [...equipo.value],
      inicio: `${fechaTurno.value}T${hora.value}`,
      duracion: duracion.value,
      sena_pagada: senaPagada.value,
      sena_importe_centavos: importeEnCentavos(senaImporte.value),
    }
    const revisada = altaEnRevision.value
    const dia = fechaTurno.value
    const pendiente = sinConexion.value
    if (anterior) {
      await api(`/api/admin/agenda/turnos/${anterior.id}`, 'PUT', { ...turno, revision: Number(anterior.revision) })
    } else if (pendiente || revisada) {
      if (pendiente && (!copia.value || copia.value.vence <= Date.now())) {
        throw new Error('La copia venció. Volvé a conectarte antes de cargar turnos.')
      }
      if (!turno.cliente) throw new Error('Ingresá un nombre para el turno.')
      if (turno.inicio <= `${calendario.format(new Date())}T${reloj.format(new Date())}`) throw new Error('Elegí un horario futuro.')
      const { sena_pagada, sena_importe_centavos, ...borrador } = turno
      await guardarAlta({
        id: revisada?.id ?? crypto.randomUUID(), cuenta: usuario.value.id,
        creada: revisada?.creada ?? Date.now(), turno: borrador,
      })
      altas.value = await leerAltas(usuario.value.id)
      if (!pendiente) await sincronizarAltas()
    } else {
      await api('/api/admin/agenda/turnos', 'POST', turno)
    }
    fecha.value = dia
    dialogo.value.close()
    limpiarTurno()
    if (pendiente) {
      aviso.value = 'Turno pendiente de sincronizar.'
      return
    }
    await cargarDia()
    await prepararCopia()
    if (!revisada) aviso.value = anterior ? 'Turno actualizado.' : 'Turno guardado.'

  })
}

function cancelarTurno(turno) {
  if (!window.confirm('¿Cancelar este turno y liberar sus horarios?')) return
  return ejecutar(async () => {
    await api(`/api/admin/agenda/turnos/${turno.id}`, 'DELETE', { revision: Number(turno.revision) })
    if (editando.value?.id === turno.id) limpiarTurno()
    await cargarDia()
    await prepararCopia()
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
    if (altas.value.length && !window.confirm('Al cerrar sesión se borrarán los turnos pendientes de este dispositivo. ¿Cerrar sesión?')) return
    await borrarDatosLocales(usuario.value.id).catch(() => {})
    await api('/api/admin/sesion', 'DELETE')
    await avisarCambioCuenta(null).catch(() => {})
    window.location.replace('/admin')
  })
}

onMounted(() => {
  document.title = 'Agenda · Zenli'
  window.addEventListener('hashchange', actualizarSeccion)
  document.addEventListener('pointerdown', cerrarMenuFuera)
  window.addEventListener('online', reconectar)
  window.addEventListener('offline', desconectar)
  window.addEventListener('focus', alVolver)
  canalSesion?.addEventListener('message', evento => {
    if (evento.data?.tipo === 'copia-borrada') {
      if (usuario.value?.id === evento.data.cuenta) quitarCopiaVisible()
      return
    }
    if (!usuario.value || usuario.value.id === evento.data) return
    usuario.value = null
    copia.value = null
    datos.value = null
    altas.value = []
    if (navigator.onLine) window.location.replace('/admin')
    else estadoCopia.value = 'La sesión cambió. Conectate e iniciá sesión para ver la agenda.'
  })
  return navigator.onLine ? reconectar() : desconectar()
})

</script>

<template>
  <div class="agenda fondo-administracion">
    <header class="cabecera-zenli">
      <a href="/" class="marca-zenli" aria-label="Zenli, portada">
        <span class="loto" aria-hidden="true"><i></i></span>
        <strong>Zenli</strong>
        <small>Centro de masajes</small>
      </a>
      <details v-if="usuario" ref="menuPerfil" class="cuenta-zenli" @keydown.esc="cerrarMenuPerfil(true)">
        <summary aria-label="Opciones de perfil">
          <span class="avatar" aria-hidden="true">{{ identidad.charAt(0).toUpperCase() }}</span>
          <span class="identidad">
            <strong>{{ identidad }}</strong>
            <small>{{ usuario.admin ? 'Administración' : 'Profesional' }}</small>
            <span class="flecha-perfil" aria-hidden="true"></span>
          </span>
        </summary>
        <div class="opciones-perfil">
          <div v-if="usuario.admin && datos" class="vistas-perfil" role="group" aria-label="Vista de agenda">
            <button type="button" :aria-pressed="!profesionalSeleccionada" :disabled="ocupado" @click="elegirVistaAgenda('')">Agenda general</button>
            <button v-for="persona in datos.profesionales" :key="persona.id" type="button"
              :aria-pressed="profesionalSeleccionada === persona.id" :disabled="ocupado" @click="elegirVistaAgenda(persona.id)">
              {{ persona.id === usuario.profesional_id ? `Mi agenda · ${persona.nombre}` : persona.nombre }}
            </button>
          </div>
          <button v-if="copia" type="button" :disabled="ocupado" @click="confirmarBorradoCopia">Borrar caché de agenda</button>
          <button v-else-if="!sinConexion" type="button" :disabled="ocupado" @click="ejecutar(prepararCopia)">Descargar copia de la agenda</button>
          <button v-if="admiteClavesAcceso && !sinConexion" type="button" :disabled="ocupado" @click="gestionarClavesAcceso">Claves de acceso</button>
          <button type="button" :disabled="ocupado || sinConexion" @click="salir">Cerrar sesión</button>
        </div>
      </details>
    </header>
    <ClavesAccesoAgenda v-if="usuario && !sinConexion && admiteClavesAcceso" ref="clavesAccesoAgenda" :api="api" />
    <LayoutAdministracion :usuario="usuario" :seccion="seccionAgenda" :solo-agenda="sinConexion">
    <main id="turnos">
      <section v-if="sinConexion" class="panel" aria-label="Conexión">
        <strong>Sin conexión</strong>
        <p v-if="copia">Última actualización: {{ calendario.format(copia.instante) }} · {{ reloj.format(copia.instante) }} · Período descargado: {{ rangoCopia }}.</p>
        <p v-if="estadoCopia">{{ estadoCopia }}</p>
        <div class="acciones">
          <button type="button" :disabled="ocupado" @click="reconectar">Intentar conectar</button>
          <button v-if="copia" type="button" class="secundario" :disabled="ocupado" @click="confirmarBorradoCopia">Borrar caché de agenda</button>
        </div>
      </section>
      <p v-else-if="estadoCopia" role="status">{{ estadoCopia }}</p>
      <dialog ref="dialogoBorrarCopia" aria-labelledby="titulo-borrar-copia"
        @close="confirmandoBorrado = false" @cancel="ocupado && $event.preventDefault()">
        <h2 id="titulo-borrar-copia">Borrar caché de agenda</h2>
        <p>Se borra solo la copia descargada de esta agenda en este dispositivo. Los turnos pendientes de sincronizar, los archivos de la app y las demás cachés se conservan.</p>
        <p v-if="sinConexion">Para volver a consultar la agenda, vas a necesitar conexión.</p>
        <p v-if="error && confirmandoBorrado" role="alert">{{ error }}</p>
        <div class="acciones-formulario">
          <button type="button" class="boton-formulario boton-formulario--cancelar" :disabled="ocupado" autofocus @click="dialogoBorrarCopia.close()">Cancelar</button>
          <button type="button" class="boton-formulario boton-formulario--guardar" :disabled="ocupado" @click="borrarCacheAgenda">Borrar copia</button>
        </div>
      </dialog>
      <section v-if="seccionAgenda === 'agenda' && altas.length" class="panel" aria-labelledby="titulo-altas-locales">
        <h2 id="titulo-altas-locales">Turnos guardados en este dispositivo</h2>
        <p>El espacio y las profesionales se comprueban al sincronizar.</p>
        <button v-if="!sinConexion && altas.some(alta => !alta.error)" type="button" :disabled="ocupado" @click="reconectar">Sincronizar ahora</button>
        <ul class="turnos">
          <li v-for="alta in altas" :key="alta.id">
            <div class="detalle-turno">
              <strong>{{ alta.turno.cliente }} · {{ alta.turno.inicio.replace('T', ' · ') }}</strong>
              <small v-if="alta.error" class="marca-bloqueo">{{ alta.error }}</small>
              <small v-else>Pendiente de sincronizar</small>
            </div>
            <div class="acciones">
              <button v-if="alta.error" type="button" :disabled="ocupado" @click="revisarAlta(alta)">Revisar turno</button>
              <button type="button" class="secundario" :disabled="ocupado" @click="descartarAlta(alta)">Descartar</button>
            </div>
          </li>
        </ul>
      </section>
      <ClientesAgenda v-if="seccionAgenda === 'clientes' && !sinConexion && usuario" :api="api" :ficha-id="fichaId"
        @seleccionar="fichaId = $event" @ver-turno="abrirResultado" />
      <HorariosPersonales v-if="esSeccionPersonal" :seccion="seccionAgenda" :consultar="api" />
      <h1 v-if="seccionAgenda === 'mensajes'">Mensajes privados</h1>
      <p v-if="error && !modalAbierto && !modoBloqueo && !mensajeAbierto && !preparacionEditada && !disponibilidadAbierta && !buscandoTurnos && !turnoAsistencia && !confirmandoBorrado" role="alert">{{ error }}</p>
      <p v-if="aviso" role="status">{{ aviso }}</p>
      <p v-if="!datos && ocupado" role="status">Cargando agenda…</p>

      <template v-if="datos">
        <div v-show="seccionAgenda === 'agenda'" class="tablero">
        <section class="panel acciones-rapidas" aria-label="Acciones de agenda">
            <button type="button" class="nuevo-turno" :disabled="ocupado" @click="abrirNuevoTurno"><span aria-hidden="true">＋</span> Nuevo turno</button>
            <button v-if="!sinConexion" type="button" class="secundario" :disabled="ocupado" @click="abrirBusqueda">Buscar turnos</button>
            <button type="button" class="secundario boton-hoja-dia-rapida" :disabled="ocupado" @click="abrirHojaDelDia">📋 Hoja del día</button>
            <button v-if="usuario.admin && !sinConexion" type="button" class="secundario" :disabled="ocupado || !datos.profesionales.length" @click="abrirNuevoBloqueo">Agregar horario no disponible</button>
            <button v-if="!sinConexion" type="button" class="secundario" :disabled="ocupado || !datos.profesionales.length" @click="abrirDisponibilidad">
              {{ !usuario.admin || (perfilEnVista && perfilEnVista === usuario.profesional_id) ? 'Ver mi disponibilidad' : 'Ver disponibilidad' }}
            </button>
            <button v-if="!agendaInstalada && !sinConexion" type="button" class="secundario" :disabled="instalandoAgenda" @click="descargarAgenda">Descargar app</button>
            <a v-if="usuario.admin && !sinConexion" :href="perfilEnVista ? `/admin?profesional=${perfilEnVista}#horarios-profesionales` : '/admin#horarios-profesionales'" class="enlace-accion">Editar horarios <span aria-hidden="true">↗</span></a>
        </section>
        <section class="panel panel-calendario" aria-labelledby="titulo-agenda">
          <div class="barra cabecera-calendario">
            <div>
              <h1 id="titulo-agenda">{{ tituloAgenda }}</h1>
              <p>{{ encabezadoPeriodo }}</p>
            </div>
            <div class="selector-vista" aria-label="Vista de calendario">
              <button v-for="opcion in vistas" :key="opcion.id" type="button" :aria-pressed="vista === opcion.id"
                :disabled="ocupado" @click="cambiarVista(opcion.id)">{{ opcion.nombre }}</button>
            </div>
          </div>
          <div class="barra controles-calendario">
            <div class="acciones">
              <button type="button" class="secundario" aria-label="Período anterior" :disabled="ocupado" @click="navegar(-1)">‹</button>
              <button type="button" class="secundario" :disabled="ocupado" @click="cambiarFecha(calendario.format(new Date()))">Hoy</button>
              <button type="button" class="secundario" aria-label="Período siguiente" :disabled="ocupado" @click="navegar(1)">›</button>
            </div>
            <label>Ir a fecha <input :value="fecha" type="date" :disabled="ocupado" @change="cambiarFecha($event.target.value)"></label>
          </div>
          <section v-if="sinConexion" class="ocupacion-copiada" aria-labelledby="titulo-ocupacion-copiada">
            <h2 id="titulo-ocupacion-copiada">Espacios ocupados · {{ etiquetaDia.format(diaUtc(fecha)) }}</h2>
            <p>Según la última copia. La disponibilidad se vuelve a comprobar al sincronizar.</p>
            <p v-if="!ocupacionesDelDia">La copia no incluye ocupaciones para esta fecha.</p>
            <p v-else-if="!ocupacionesDelDia.length">La copia no registra espacios ocupados para este día.</p>
            <ul v-else class="huecos">
              <li v-for="espacio in ocupacionesDelDia" :key="espacio.id"><strong>{{ espacio.nombre }}</strong> · {{ espacio.tramos.join(', ') }}</li>
            </ul>
          </section>
          <p v-if="periodoSinCopia">La copia no cubre todo este período. Elegí una fecha descargada y usá la vista Día.</p>
          <template v-else>
          <CalendarioHoras v-if="vista !== 'mes'" :dias="dias" :fecha="fecha" :zona="zona" :ocupado="ocupado"
            @seleccionar="fecha = $event" @ver-turno="verEnAgenda" />
          <div v-else class="calendario">
            <div v-for="nombre in ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom']" :key="nombre" class="dia-semana">{{ nombre }}</div>
            <span v-for="n in offsetMes" :key="`vacio-${n}`" aria-hidden="true"></span>
            <button v-for="dia in dias" :key="dia.clave" type="button" class="celda"
              :class="{ propia: dia.propios }" :aria-pressed="fecha === dia.clave" :disabled="ocupado" @click="fecha = dia.clave">
              <strong>{{ dia.numero }}</strong>
              <small v-if="dia.franjasAtencion?.length === 0" class="mes-sin-atencion"><img class="icono-loto" :src="lotoUrl" alt=""> {{ dia.feriado ? 'Feriado · Sin atención' : 'Sin atención' }}</small>
              <small v-if="dia.citas">{{ dia.citas }} {{ dia.citas === 1 ? 'turno' : 'turnos' }}</small>
              <small v-if="dia.bloqueos" class="marca-bloqueo">{{ dia.bloqueos }} {{ dia.bloqueos === 1 ? 'horario no disponible' : 'horarios no disponibles' }}</small>
              <small v-if="dia.propios" class="mio">{{ dia.propios }} {{ dia.propios === 1 ? 'propio' : 'propios' }}</small>
            </button>
          </div>
          <ul class="leyenda" aria-label="Referencias del calendario">
            <li><i class="muestra-turno"></i> Turno</li>
            <li><i class="muestra-equipo"></i> Equipo incompleto</li>
            <li><i class="muestra-bloqueo"></i> Horario no disponible</li>
            <li><i class="muestra-preparacion"></i> Preparación</li>
            <li v-if="dias.some(dia => dia.franjasAtencion !== null)"><i class="muestra-sin-atencion"></i> Sin atención</li>
          </ul>
          <div class="barra cabecera-detalle-dia">
            <h2 class="detalle-dia">Turnos del {{ etiquetaDia.format(diaUtc(fecha)) }}</h2>
            <button type="button" class="secundario boton-hoja-dia" :disabled="ocupado" @click="abrirHojaDelDia">📋 Ver hoja del día</button>
          </div>
          <p v-if="feriadoSeleccionado">Feriado · Sin atención habitual</p>
          <button v-if="usuario.admin && !sinConexion" type="button" class="secundario" :disabled="ocupado" @click="alternarFeriado">
            {{ feriadoSeleccionado ? 'Quitar feriado de este día' : 'Marcar este día como feriado' }}
          </button>
          <p v-if="!turnosDia.length">No hay turnos ni horarios no disponibles para este día.</p>
          <ul v-else class="turnos">
            <li v-for="turno in turnosDia" :key="turno.id" :id="`agenda-fila-${turno.id}`" tabindex="-1" :class="{ propio: !turno.bloqueado && esPropio(turno), bloqueado: turno.bloqueado }">
              <strong>{{ horarioVisible(turno) }}</strong>
              <div class="detalle-turno">
                <span v-if="turno.bloqueado" class="marca-bloqueo">Profesional no disponible</span>
                <span v-else-if="turno.esPreparacion">Preparación del espacio</span>
                <span v-else>
                  {{ turno.servicio }} · {{ turno.cliente }}
                  <span v-if="turno.cliente_no_contactar" class="badge-no-contactar" title="El cliente solicitó no ser contactado">🚫 No contactar</span>
                  <small v-if="esPropio(turno)" class="mio">Mi turno</small>
                </span>
                <small v-if="turno.asistencia" class="asistencia" :class="turno.asistencia">{{ estadosAsistencia[turno.asistencia] }}</small>
                <button v-if="!sinConexion && turno.cliente_id" type="button" class="secundario" :disabled="ocupado" @click="mostrarFicha(turno.cliente_id)">Ver ficha</button>
                <a v-if="!sinConexion && turno.cliente_telefono && enlaceWhatsAppTurno(turno)"
                   :href="turno.cliente_no_contactar ? '#' : enlaceWhatsAppTurno(turno)"
                   :target="turno.cliente_no_contactar ? undefined : '_blank'"
                   rel="noopener noreferrer"
                   class="boton-wa-turno"
                   :class="{ 'boton-wa-turno--precaucion': turno.cliente_no_contactar }"
                   :title="turno.cliente_no_contactar ? 'Precaución: El cliente solicitó NO ser contactado' : 'Enviar recordatorio por WhatsApp'"
                   @click="accionarWhatsApp(turno, $event)">
                  <span class="icono-wa" aria-hidden="true">💬</span> WhatsApp
                  <span v-if="turno.cliente_no_contactar" class="alerta-wa-no-contactar" aria-hidden="true">⚠</span>
                </a>
                <!-- Para habilitar llamadas directas en el futuro, descomentar la siguiente línea:
                <a v-if="!sinConexion && turno.cliente_telefono" :href="'tel:' + turno.cliente_telefono" class="boton-telefono-turno" title="Llamar al cliente">Llamar</a>
                -->
                <small v-if="turno.esPreparacion">{{ turno.espacio }}</small>
                <small v-else><template v-if="!turno.bloqueado && datos.espacios.length > 1">{{ turno.espacio }} · </template>{{ turno.profesionales }}</small>
                <small v-if="!turno.bloqueado && turno.equipo_incompleto" class="marca-bloqueo">⚠ Faltan profesionales por asignar</small>
                <small v-if="!sinConexion && !turno.bloqueado && !turno.esPreparacion">Seña: {{ turno.sena_pagada ? 'pagada' : 'no pagada' }}<template v-if="turno.sena_importe_centavos != null"> · $ {{ importeVisible(turno.sena_importe_centavos) }}</template></small>
              </div>
              <a v-if="turno.bloqueado && usuario.admin && !sinConexion" class="enlace-accion" :href="`/admin?profesional=${turno.profesional_id}#horarios-profesionales`">Editar horario no disponible</a>
              <div v-else-if="turno.esPreparacion && turno.editable" class="acciones">
                <button type="button" :disabled="ocupado" @click="editarPreparacion(turno)">Editar</button>
                <button type="button" class="secundario" :disabled="ocupado" @click="eliminarPreparacion(turno)">Eliminar</button>
              </div>
              <div v-else-if="turno.editable" class="acciones">
                <button v-if="!sinConexion" type="button" class="secundario" :disabled="ocupado" @click="abrirAsistencia(turno)">Asistencia</button>
                <button type="button" :disabled="ocupado" @click="editarTurno(turno)">Editar</button>
                <button type="button" class="secundario" :disabled="ocupado" @click="cancelarTurno(turno)">Cancelar turno</button>
              </div>
              <small v-else-if="!sinConexion && !turno.bloqueado && !turno.esPreparacion">Turno compartido · lo administra el equipo de administración</small>
            </li>
          </ul>
          </template>
        </section>

        <aside class="columna-resumen" aria-label="Resumen de agenda">
          <section class="panel solicitudes" aria-labelledby="titulo-solicitudes">
            <h2 id="titulo-solicitudes">Solicitudes pendientes</h2>
            <p>Las reservas se gestionan desde la agenda interna.</p>
          </section>
          <section v-if="!periodoSinCopia" class="panel" aria-labelledby="titulo-resumen">
            <h2 id="titulo-resumen">Resumen del período</h2>
            <dl class="resumen">
              <div v-for="item in resumen" :key="item.nombre"><dt>{{ item.nombre }}</dt><dd>{{ item.total }}</dd></div>
            </dl>
          </section>
          <section v-if="horarioHabitual?.por_franjas" class="panel" aria-labelledby="titulo-horario">
            <h2 id="titulo-horario">{{ perfilEnVista === usuario.profesional_id ? 'Mi horario habitual' : 'Horario habitual' }}</h2>
            <dl v-if="franjasHabituales.length" class="resumen">
              <div v-for="(franja, indice) in franjasHabituales" :key="indice">
                <dt>{{ diasSemana[franja.dia_semana - 1] }}</dt><dd>{{ franja.hora_inicio.slice(0, 5) }}–{{ franja.hora_fin.slice(0, 5) }}</dd>
              </div>
            </dl>
            <p v-else>Sin franjas semanales vigentes.</p>
          </section>
        </aside>
        </div>

        <dialog ref="dialogoBusqueda" aria-labelledby="titulo-busqueda-turnos"
          @close="cerrarBusqueda" @cancel="ocupado && $event.preventDefault()">
          <div class="barra">
            <h2 id="titulo-busqueda-turnos">Buscar turnos</h2>
            <button type="button" class="boton-formulario boton-formulario--cancelar" :disabled="ocupado" @click="dialogoBusqueda.close()">Cerrar</button>
          </div>
          <p v-if="error && buscandoTurnos" role="alert">{{ error }}</p>
          <form @submit.prevent="buscarTurnos">
            <label>Nombre del cliente <input v-model="nombreBuscado" type="search" required minlength="2" maxlength="150" autocomplete="off" autofocus :disabled="ocupado"></label>
            <button type="submit" :disabled="ocupado">Buscar</button>
          </form>
          <div v-if="resultadosTurnos" aria-live="polite">
            <p v-if="!resultadosTurnos.turnos.length">No se encontraron turnos.</p>
            <ul v-else class="turnos resultados-turnos">
              <li v-for="turno in resultadosTurnos.turnos" :key="turno.id">
                <div>
                  <strong>{{ turno.cliente }}</strong>
                  <span v-if="turno.cliente_no_contactar" class="badge-no-contactar" title="El cliente solicitó no ser contactado">🚫 No contactar</span>
                  <p>{{ calendario.format(new Date(turno.inicio)) }} · {{ horarioVisible(turno) }} · {{ turno.servicio }}</p>
                  <small class="asistencia" :class="turno.asistencia">{{ estadosAsistencia[turno.asistencia] }}</small>
                </div>
                <div class="acciones">
                  <a v-if="turno.cliente_telefono && enlaceWhatsAppTurno(turno)"
                     :href="turno.cliente_no_contactar ? '#' : enlaceWhatsAppTurno(turno)"
                     :target="turno.cliente_no_contactar ? undefined : '_blank'"
                     rel="noopener noreferrer"
                     class="boton-wa-turno"
                     :class="{ 'boton-wa-turno--precaucion': turno.cliente_no_contactar }"
                     :title="turno.cliente_no_contactar ? 'Precaución: El cliente solicitó NO ser contactado' : 'Enviar recordatorio por WhatsApp'"
                     @click="accionarWhatsApp(turno, $event)">
                    <span class="icono-wa" aria-hidden="true">💬</span> WhatsApp
                    <span v-if="turno.cliente_no_contactar" class="alerta-wa-no-contactar" aria-hidden="true">⚠</span>
                  </a>
                  <!-- Para habilitar llamadas directas en el futuro, descomentar la siguiente línea:
                  <a v-if="turno.cliente_telefono" :href="'tel:' + turno.cliente_telefono" class="boton-telefono-turno" title="Llamar al cliente">Llamar</a>
                  -->
                  <button v-if="turno.cliente_id" type="button" class="secundario" :disabled="ocupado" @click="mostrarFicha(turno.cliente_id)">Ver ficha</button>
                  <button type="button" class="secundario" :disabled="ocupado" @click="abrirResultado(turno)">Ver turno</button>
                </div>
              </li>
            </ul>
            <p v-if="resultadosTurnos.hay_mas">Se muestran los 50 turnos más recientes. Ingresá un nombre más completo para acotar la búsqueda.</p>
          </div>
        </dialog>

        <dialog ref="dialogoAsistencia" aria-labelledby="titulo-asistencia"
          @close="turnoAsistencia = null" @cancel="ocupado && $event.preventDefault()">
          <div class="barra">
            <h2 id="titulo-asistencia">Registrar asistencia</h2>
          </div>
          <template v-if="turnoAsistencia">
            <p>{{ turnoAsistencia.cliente }} · {{ horarioVisible(turnoAsistencia) }}</p>
            <p v-if="error" role="alert">{{ error }}</p>
            <form @submit.prevent="guardarAsistencia">
              <label>Estado
                <select v-model="asistenciaNueva" autofocus :disabled="ocupado">
                  <option v-for="(etiqueta, valor) in estadosAsistencia" :key="valor" :value="valor">{{ etiqueta }}</option>
                </select>
              </label>
              <div class="acciones-formulario">
                <button type="button" class="boton-formulario boton-formulario--cancelar" :disabled="ocupado" @click="dialogoAsistencia.close()">Cancelar</button>
                <button type="submit" class="boton-formulario boton-formulario--guardar" :disabled="ocupado || asistenciaNueva === turnoAsistencia.asistencia">Guardar asistencia</button>
              </div>
            </form>
          </template>
        </dialog>

        <dialog ref="dialogoInstalacion" aria-labelledby="titulo-descargar-agenda">
          <div class="barra">
            <h2 id="titulo-descargar-agenda">Descargar Agenda Zenli</h2>
            <button type="button" class="secundario" @click="dialogoInstalacion.close()">Cerrar</button>
          </div>
          <p><strong>Android.</strong> En Chrome, abrí el menú <strong>⋮</strong>, tocá <strong>Instalar aplicación</strong> o <strong>Agregar a la pantalla principal</strong> y seguí los pasos.</p>
          <p><strong>iPhone.</strong> En Safari, tocá <strong>Compartir → Agregar a inicio</strong>. Si aparece <strong>Abrir como app web</strong>, activalo y tocá <strong>Agregar</strong>.</p>
          <p><strong>Computadora.</strong> Buscá la opción <strong>Instalar Agenda Zenli</strong> en la barra de direcciones o en el menú de Chrome.</p>
        </dialog>

        <dialog ref="dialogoDisponibilidad" aria-labelledby="titulo-disponibilidad"
          @close="disponibilidadAbierta = false" @cancel="ocupado && $event.preventDefault()">
          <div class="barra">
            <h2 id="titulo-disponibilidad">Disponibilidad</h2>
            <button type="button" class="secundario" :disabled="ocupado" @click="dialogoDisponibilidad.close()">Cerrar</button>
          </div>
          <p v-if="error && disponibilidadAbierta" role="alert">{{ error }}</p>
          <p>Consultá los horarios libres de una profesional en cada sala para la fecha elegida.</p>
          <form @submit.prevent="consultarDisponibilidad">
            <label v-if="usuario.admin">Profesional
              <select v-model="profesionalDisponibilidad" :disabled="ocupado" required @change="disponibilidad = null">
                <option disabled value="">Elegí una profesional</option>
                <option v-for="persona in datos.profesionales" :key="persona.id" :value="persona.id">{{ persona.nombre }}</option>
              </select>
            </label>
            <label>Fecha <input v-model="fechaDisponibilidad" type="date" :disabled="ocupado" required @change="disponibilidad = null"></label>
            <button :disabled="ocupado">Consultar</button>
          </form>
          <p v-if="ocupado" role="status">Buscando horarios libres…</p>
          <div v-if="disponibilidad" class="resultado-disponibilidad" aria-live="polite">
            <p v-if="disponibilidad.jornada_manual">Modo manual: se muestran los huecos de todo el día. Confirmá el horario de atención con la profesional.</p>
            <p v-if="!disponibilidad.hay_franjas">La profesional no tiene horario de atención configurado para esta fecha.</p>
            <p v-else-if="!disponibilidad.espacios.length">No hay espacios activos.</p>
            <p v-else-if="!disponibilidad.espacios.some(espacio => espacio.huecos.length)">No quedan horarios libres para ese día.</p>
            <template v-else>
              <section v-for="espacio in disponibilidad.espacios" :key="espacio.id">
                <h3 v-if="disponibilidad.espacios.length > 1">{{ espacio.nombre }}</h3>
                <p v-if="!espacio.huecos.length">Sin horarios libres.</p>
                <ul v-else class="huecos">
                  <li v-for="hueco in espacio.huecos" :key="hueco.inicio">
                    {{ reloj.format(new Date(hueco.inicio)) }}–{{ horaHueco(hueco.fin) }}
                  </li>
                </ul>
              </section>
            </template>
          </div>
        </dialog>

        <dialog ref="dialogoPreparacion" aria-labelledby="titulo-preparacion" @close="preparacionEditada = null" @cancel="ocupado && $event.preventDefault()">
          <div class="barra">
            <h2 id="titulo-preparacion">Editar preparación del espacio</h2>
            <button type="button" class="boton-formulario boton-formulario--cancelar" :disabled="ocupado" @click="dialogoPreparacion.close()">Cerrar</button>
          </div>
          <p v-if="error && preparacionEditada" role="alert">{{ error }}</p>
          <form @submit.prevent="guardarPreparacion">
            <label>Desde <input v-model="inicioPreparacion" type="datetime-local" required></label>
            <label>Hasta <input v-model="finPreparacion" type="datetime-local" required></label>
            <button type="submit" :disabled="ocupado" class="boton-formulario boton-formulario--guardar">Guardar cambios</button>
          </form>
        </dialog>

        <dialog ref="dialogoBloqueo" aria-labelledby="titulo-bloqueo" @close="modoBloqueo = false" @cancel="ocupado && $event.preventDefault()">
          <div class="barra">
            <h2 id="titulo-bloqueo">Horario no disponible de una profesional</h2>
            <button type="button" class="boton-formulario boton-formulario--cancelar" :disabled="ocupado" @click="dialogoBloqueo.close()">Cerrar</button>
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
            <button type="submit" :disabled="ocupado" class="boton-formulario boton-formulario--guardar">Guardar horario no disponible</button>
          </form>
        </dialog>

        <dialog ref="dialogo" aria-labelledby="titulo-turno" @close="modalAbierto = false" @cancel="ocupado && $event.preventDefault()">
          <div class="barra">
            <h2 id="titulo-turno">{{ editando ? 'Editar turno' : 'Nuevo turno' }}</h2>
            <button type="button" class="boton-formulario boton-formulario--cancelar" :disabled="ocupado" @click="dialogo.close()">Cerrar</button>
          </div>
          <p v-if="error && modalAbierto" role="alert">{{ error }}</p>
            <p v-if="!datos.servicios.length">Creá un servicio en <a href="/admin">Administración</a> para cargar turnos.</p>
            <p v-else-if="!datos.espacios.length">Hace falta al menos un espacio activo.</p>
            <form v-else @submit.prevent="guardarTurno">
              <p v-if="sinConexion">El turno se guardará pendiente en este dispositivo. Se confirmará cuando el servidor compruebe la disponibilidad.</p>
              <label>Servicio
                <select v-model="servicio" required>
                  <option disabled value="">Elegí un servicio</option>
                  <option v-for="item in datos.servicios" :key="item.id" :value="item.id">{{ item.nombre }}{{ servicioCompleto(item) ? '' : ' · configuración incompleta' }}</option>
                </select>
              </label>
              <p v-if="servicio">Preparación del espacio después del turno: {{ preparacion }} min.</p>
              <p v-if="servicioElegido && !servicioCompleto(servicioElegido)">Falta configurar {{ [servicioElegido.duracion_minima == null ? 'la duración informativa' : '', servicioElegido.profesionales_requeridos == null ? 'la cantidad de profesionales' : '', !datos.preparacion ? 'la preparación general' : ''].filter(Boolean).join(', ') }}. Podés guardar el turno igualmente.</p>
              <label>Fecha del turno <input v-model="fechaTurno" type="date" required></label>
              <label>Nombre del cliente <input v-model="cliente" maxlength="150" required :readonly="!!clienteVinculado" @input="fichasCoincidentes = null"></label>
              <div v-if="!sinConexion">
                <template v-if="clienteVinculado">
                  <p>Ficha vinculada al turno.</p>
                  <button type="button" class="secundario" :disabled="ocupado" @click="clienteVinculado = null">Quitar vínculo</button>
                </template>
                <template v-else>
                  <button type="button" class="secundario" :disabled="ocupado || cliente.trim().length < 2" @click="buscarFichaParaTurno">Buscar ficha</button>
                  <button v-if="!editando" type="button" class="secundario" :disabled="ocupado" @click="nuevaFichaTurno.abrir({ nombre: cliente.trim() })">Crear ficha</button>
                  <template v-if="fichasCoincidentes">
                    <ul v-if="fichasCoincidentes.clientes.length" class="turnos">
                      <li v-for="ficha in fichasCoincidentes.clientes" :key="ficha.id">
                        <div><strong>{{ ficha.nombre }}</strong><small v-if="ficha.telefono || ficha.email"> · {{ ficha.telefono || ficha.email }}</small></div>
                        <button type="button" class="secundario" :disabled="ocupado" @click="seleccionarFichaParaTurno(ficha)">Vincular ficha</button>
                      </li>
                    </ul>
                    <p v-else>No se encontraron fichas con ese nombre.</p>
                    <p v-if="fichasCoincidentes.hay_mas">Ingresá un nombre más completo.</p>
                  </template>
                </template>
              </div>
              <label v-if="!sinConexion && !altaEnRevision" class="opcion"><input v-model="senaPagada" type="checkbox"> Seña pagada</label>
              <label v-if="!sinConexion && !altaEnRevision">Importe de la seña (opcional) <input v-model.trim="senaImporte" type="text" inputmode="decimal" placeholder="0,00"></label>
              <div class="barra">
                <label>Hora (24 horas) <EntradaHora v-model="hora" required /></label>
                <label>Duración (min)
                  <input v-model.number="duracion" type="number" :min="editando && Number(editando.duracion) < 20 ? Number(editando.duracion) : 20" max="1440" step="1" list="duraciones-AgendaZenli" required>
                </label>
                <datalist id="duraciones-AgendaZenli">
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
              <p v-if="conflictoEspacioSinConexion" class="aviso-ocupacion" role="status">
                <strong>{{ espacioElegido?.nombre ?? 'El espacio seleccionado' }} ya figuraba ocupado</strong> en la última copia para el turno o su preparación. Podés elegir otro horario o guardar el turno pendiente. El servidor volverá a comprobar la disponibilidad al sincronizar.
              </p>
              <button type="submit" :disabled="ocupado || !fechaTurno || !servicioElegido" class="boton-formulario boton-formulario--guardar">{{ editando ? 'Guardar cambios' : (sinConexion ? 'Guardar pendiente' : 'Cargar turno') }}</button>
            </form>
        </dialog>

        <FichaClienteDialog ref="nuevaFichaTurno" :api="api" @guardada="seleccionarFichaParaTurno($event.cliente)" />

        <section v-show="seccionAgenda === 'mensajes' && !sinConexion" id="mensajes" class="panel" aria-labelledby="titulo-mensajes">
            <div class="barra">
              <h2 id="titulo-mensajes">Bandeja de mensajes</h2>
              <div class="acciones">
                <button type="button" class="secundario" :disabled="ocupado" @click="ejecutar(async () => { bandeja = await api('/api/admin/agenda/mensajes') })">Actualizar</button>
                <button type="button" :disabled="ocupado || !bandeja.contactos.length" @click="abrirMensaje">+ Nuevo mensaje</button>
              </div>
            </div>
            <p v-if="!bandeja.contactos.length">No hay contactos disponibles para enviar mensajes.</p>
            <dialog ref="dialogoMensaje" aria-labelledby="titulo-nuevo-mensaje" @close="cerrarMensaje" @cancel="ocupado && $event.preventDefault()">
              <div class="barra"><h2 id="titulo-nuevo-mensaje">Nuevo mensaje</h2><button type="button" class="boton-formulario boton-formulario--cancelar" :disabled="ocupado" @click="dialogoMensaje.close()">Cerrar</button></div>
              <p v-if="error && mensajeAbierto" role="alert">{{ error }}</p>
            <form @submit.prevent="enviarMensaje">
              <label>Para
                <select v-model="destinatario" required>
                  <option disabled value="">Elegí un contacto</option>
                  <option v-for="contacto in bandeja.contactos" :key="contacto.id" :value="contacto.id">{{ contacto.nombre }}</option>
                </select>
              </label>
              <label>Mensaje <textarea v-model="texto" maxlength="2000" rows="3" required></textarea></label>
              <button type="submit" :disabled="ocupado" class="boton-formulario boton-formulario--guardar">Enviar mensaje</button>
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

        <dialog ref="dialogoHojaDia" class="modal-hoja-dia" aria-labelledby="titulo-hoja-dia" @close="hojaDiaAbierta = false" @cancel="ocupado && $event.preventDefault()">
          <div class="barra-acciones-hoja no-imprimir">
            <div class="titulo-modal">
              <h2 id="titulo-hoja-dia">Hoja del día · Agenda Zenli</h2>
              <small>Planilla diaria para trabajo en gabinete y control de jornada</small>
            </div>
            <div class="acciones">
              <button type="button" class="boton-imprimir-hoja" @click="imprimirHojaDelDia">
                🖨️ Imprimir / Guardar PDF
              </button>
              <button type="button" class="boton-formulario boton-formulario--cancelar" @click="dialogoHojaDia.close()">
                Cerrar
              </button>
            </div>
          </div>

          <article class="hoja-papel-agenda">
            <header class="encabezado-hoja">
              <div class="logo-y-marca">
                <img class="icono-loto-hoja" :src="lotoUrl" alt="Zenli">
                <div>
                  <h1 class="marca-zenli-hoja">ZENLI MASOTERAPIA</h1>
                  <p class="sede-zenli-hoja">Sede Mendoza · Planilla de Jornada</p>
                </div>
              </div>
              <div class="caja-fecha-hoja">
                <span class="fecha-dia-semana">{{ fechaFormateadaHoja.semana }}</span>
                <span class="fecha-dia-numero">{{ fechaFormateadaHoja.dia }}</span>
                <span class="fecha-mes-ano">{{ fechaFormateadaHoja.mesAno }}</span>
              </div>
            </header>

            <div class="franja-meta-hoja">
              <div class="meta-item">
                <span class="meta-etiqueta">Profesional:</span>
                <strong class="meta-valor">{{ nombreProfesionalEnVista }}</strong>
              </div>
              <div class="meta-item">
                <span class="meta-etiqueta">Turnos programados:</span>
                <strong class="meta-valor">{{ turnosValidosHoja.length }}</strong>
              </div>
              <div class="meta-item">
                <span class="meta-etiqueta">Señas abonadas:</span>
                <strong class="meta-valor">{{ senasPagadasHoja }} de {{ turnosValidosHoja.length }}</strong>
              </div>
              <div v-if="feriadoSeleccionado" class="meta-item meta-feriado">
                <span class="meta-etiqueta">Atención:</span>
                <strong class="meta-valor">Feriado declarado</strong>
              </div>
            </div>

            <table class="tabla-hoja-agenda">
              <thead>
                <tr>
                  <th class="col-hora">Horario</th>
                  <th class="col-cliente">Cliente</th>
                  <th class="col-telefono">Teléfono</th>
                  <th class="col-servicio">Tratamiento / Servicio</th>
                  <th class="col-espacio">Espacio</th>
                  <th class="col-profesional">Profesional</th>
                  <th class="col-sena">Seña</th>
                  <th class="col-asistencia">Asistencia</th>
                  <th class="col-notas">Observaciones de sesión</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!turnosDia.length">
                  <td colspan="9" class="sin-turnos-hoja">No hay turnos registrados para esta fecha.</td>
                </tr>
                <tr v-for="item in turnosDia" :key="item.id" :class="{ 'fila-bloqueo': item.bloqueado, 'fila-preparacion': item.esPreparacion }">
                  <td class="col-hora celda-hora">
                    <strong>{{ horarioVisible(item) }}</strong>
                  </td>
                  <template v-if="item.bloqueado">
                    <td colspan="8" class="celda-bloqueo-texto">
                      ⛔ Horario no disponible · {{ item.profesionales }}
                    </td>
                  </template>
                  <template v-else-if="item.esPreparacion">
                    <td colspan="8" class="celda-preparacion-texto">
                      🧴 Preparación de espacio: {{ item.espacio }}
                    </td>
                  </template>
                  <template v-else>
                    <td class="col-cliente">
                      <strong>{{ item.cliente }}</strong>
                      <div v-if="item.cliente_no_contactar" class="aviso-hoja-no-contactar">🚫 NO CONTACTAR</div>
                    </td>
                    <td class="col-telefono">
                      {{ item.cliente_telefono || '—' }}
                    </td>
                    <td class="col-servicio">
                      {{ item.servicio }}
                      <small class="minutos-duracion">({{ item.duracion }} min)</small>
                    </td>
                    <td class="col-espacio">
                      {{ item.espacio }}
                    </td>
                    <td class="col-profesional">
                      {{ item.profesionales }}
                    </td>
                    <td class="col-sena">
                      <span v-if="item.sena_pagada" class="sena-si">
                        ✓ Pagada<template v-if="item.sena_importe_centavos"> ${{ importeVisible(item.sena_importe_centavos) }}</template>
                      </span>
                      <span v-else class="sena-no">
                        [ ] Pendiente<template v-if="item.sena_importe_centavos"> ${{ importeVisible(item.sena_importe_centavos) }}</template>
                      </span>
                    </td>
                    <td class="col-asistencia">
                      <span v-if="item.asistencia" class="asistencia-impresa">{{ estadosAsistencia[item.asistencia] }}</span>
                      <span v-else class="casilleros-asistencia">[ ] Asistió &nbsp; [ ] Ausente</span>
                    </td>
                    <td class="col-notas celda-renglones">
                      <div class="linea-punteada"></div>
                      <div class="linea-punteada"></div>
                    </td>
                  </template>
                </tr>
              </tbody>
            </table>

            <footer class="pie-hoja-agenda">
              <div class="bloque-anotaciones">
                <h3>Anotaciones de la jornada (zonas de dolor, preferencias, productos sugeridos):</h3>
                <div class="renglon-cuaderno"></div>
                <div class="renglon-cuaderno"></div>
                <div class="renglon-cuaderno"></div>
              </div>
              <div class="bloque-cierre">
                <div class="checks-cierre">
                  <span>[ ] Planilla y caja controladas</span>
                  <span>[ ] Gabinetes e insumos listos</span>
                </div>
                <div class="firma-responsable">
                  <span>Firma responsable de turno: ____________________________________</span>
                </div>
              </div>
            </footer>
          </article>
        </dialog>

        <dialog ref="dialogoConfirmarWA" class="modal-confirmar-wa" aria-labelledby="titulo-confirmar-wa" @close="turnoConfirmarWA = null" @cancel="turnoConfirmarWA = null">
          <div class="caja-confirmar-wa">
            <div class="icono-alerta-wa" aria-hidden="true">🚫</div>
            <h3 id="titulo-confirmar-wa">Atención: Cliente no contactable</h3>
            <p class="advertencia-wa">
              El cliente <strong>{{ turnoConfirmarWA?.cliente }}</strong> solicitó explícitamente <em>NO ser contactado</em> por ningún medio.
            </p>
            <p class="detalle-advertencia-wa">
              Para evitar enviar mensajes o recordatorios por error, se requiere confirmación consciente. ¿Realmente necesitás abrir WhatsApp para este cliente?
            </p>
            <div class="acciones-modal-wa">
              <button type="button" class="boton-formulario boton-formulario--cancelar" autofocus @click="dialogoConfirmarWA.close()">
                Cancelar (No contactar)
              </button>
              <button type="button" class="boton-forzar-wa" @click="abrirWhatsAppConfirmado">
                Sí, abrir WhatsApp de todos modos
              </button>
            </div>
          </div>
        </dialog>
      </template>
    </main>
    </LayoutAdministracion>
  </div>
</template>

<style scoped>
.agenda main > :is(h1, [role=status]) { color: #fff3d8; text-shadow: 0 1px 4px #03130e; }
.cabecera-zenli { position: relative; display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 1rem; padding: .9rem max(1rem, (100% - 94rem) / 2); background: #08251b66; border-bottom: 1px solid #c49d544f; color: #fff3d8; }
.marca-zenli { grid-column: 2; color: #efcf88; text-align: center; text-decoration: none; display: grid; justify-items: center; }
.marca-zenli strong { font: 400 clamp(2.7rem, 5vw, 4rem)/.95 Georgia, serif; }
.marca-zenli small { margin-top: .35rem; text-transform: uppercase; letter-spacing: .17em; font-size: .58rem; }
.loto { position: relative; width: 2.5rem; height: 1rem; display: block; }
.loto::before, .loto::after, .loto i { content: ''; width: .78rem; height: .78rem; border: 1px solid currentColor; border-radius: 85% 0 85% 0; position: absolute; left: 50%; bottom: .1rem; }
.loto i { transform: translateX(-50%) rotate(-45deg); bottom: .25rem; }
.loto::before { transform: translateX(-105%) rotate(-78deg); }
.loto::after { transform: translateX(4%) rotate(-12deg); }
.cuenta-zenli { position: relative; justify-self: end; min-width: 0; z-index: 5; }
.cuenta-zenli summary { display: flex; align-items: center; gap: .65rem; padding: .55rem .8rem; border: 1px solid #d8bc7855; border-radius: .7rem; background: #0b2b20; cursor: pointer; list-style: none; }
.cuenta-zenli summary::-webkit-details-marker { display: none; }
.avatar { border-radius: 50%; background: #dfb969; color: #15372a; min-width: 1.8rem; height: 1.8rem; display: grid; place-items: center; font-weight: 750; }
.identidad { min-width: 0; display: grid; font-size: .82rem; overflow-wrap: anywhere; }
.identidad small { color: #e8d8b2; }
.flecha-perfil { width: .42rem; height: .42rem; border: solid #e8d8b2; border-width: 0 2px 2px 0; transform: rotate(45deg); margin: .13rem auto .18rem; }
.cuenta-zenli[open] .flecha-perfil { transform: translateY(.15rem) rotate(225deg); }
.opciones-perfil { position: absolute; top: calc(100% + .4rem); right: 0; width: min(16rem, calc(100vw - 2rem)); max-height: 65dvh; overflow-y: auto; padding: .35rem; background: #fffaf1; border: 1px solid #ded9cc; border-radius: .65rem; box-shadow: 0 .6rem 1.7rem #102c2240; }
.opciones-perfil button { display: block; width: 100%; text-align: left; padding: .55rem .65rem; color: #214d3a; background: transparent; border: 0; font-size: .85rem; overflow-wrap: anywhere; cursor: pointer; }
.opciones-perfil button:hover, .opciones-perfil [aria-pressed=true] { background: #e5eee4; }
.opciones-perfil [aria-pressed=true] { font-weight: 750; }
.vistas-perfil { padding-bottom: .25rem; margin-bottom: .22rem; border-bottom: 1px solid #ded9cc; }
.cuenta-zenli :is(summary, button):focus-visible { outline: 2px solid #b48b3d; outline-offset: 2px; }
.agenda :deep(.estructura) { width: min(100% - 2rem, 98rem); }
.agenda :deep(nav[aria-label="Menú de administración"]) { background: #103629; border-color: #264d3d; }
.agenda :deep(nav[aria-label="Menú de administración"] a) { color: #fbefcf; }
.agenda :deep(nav[aria-label="Menú de administración"] a:hover) { background: #23513c; }
.agenda :deep(nav[aria-label="Menú de administración"] [aria-current]) { background: #294f3c; box-shadow: inset 3px 0 #dfb969; }
.agenda :deep(nav[aria-label="Menú de administración"] p) { color: #e0bd77; }
.tablero { display: grid; grid-template-columns: minmax(0, 1fr) minmax(10rem, 13rem); grid-template-areas: "calendario acciones" "calendario resumen"; grid-template-rows: auto 1fr; gap: .9rem; align-items: start; }
.panel-calendario { grid-area: calendario; min-width: 0; }
.columna-resumen { grid-area: resumen; }
.cabecera-calendario h1 { margin: 0 0 .15rem; font-size: clamp(1.8rem, 3vw, 2.55rem); }
.cabecera-calendario p { margin: 0; color: #56645a; }
.selector-vista { display: flex; gap: .12rem; border: 1px solid #ded9cc; padding: .16rem; border-radius: 2rem; background: #f3eee3; }
.selector-vista button { border: 0; border-radius: 2rem; background: transparent; color: #244437; padding: .4rem .65rem; }
.selector-vista button[aria-pressed=true] { background: #174334; color: #fff9e7; }
.panel.acciones-rapidas { grid-area: acciones; display: grid; gap: .5rem; margin-bottom: 0; }
.acciones-rapidas > :is(button, a) { width: 100%; text-align: center; font-size: .85rem; }
.acciones-rapidas .nuevo-turno { background: linear-gradient(#ebc77c,#d7a546); border-color: #b4832d; color: #2f2415; font-weight: 750; }
.nuevo-turno span { margin-right: .15rem; }
.solicitudes p { margin: 0; color: #56645a; font-size: .86rem; }
.resumen { margin: 0; }
.resumen div { display: flex; justify-content: space-between; gap: .3rem; border-top: 1px solid #e4dac3; padding-block: .45rem; font-size: .85rem; }
.resumen dd { margin: 0; font-weight: 750; }
.leyenda { display: flex; gap: .5rem 1rem; flex-wrap: wrap; padding: 0; list-style: none; font-size: .76rem; }
.leyenda li { display: inline-flex; align-items: center; gap: .32rem; }
.leyenda i { width: .9rem; height: .65rem; border-radius: .2rem; }
.muestra-turno { background: #a9cfae; }.muestra-equipo { background: #e7cb89; }.muestra-bloqueo { background: #deb4b6; }.muestra-preparacion { background: #ccbbd6; }
.muestra-sin-atencion { background: repeating-linear-gradient(135deg, #f0eee7 0 3px, #d7d4ca 3px 4px); border: 1px solid #cfcbbf; }
.aviso-ocupacion { margin: 0; padding: .65rem; border: 1px solid #dbb976; border-radius: .4rem; background: #fcf0d4; color: #573b17; }
.ocupacion-copiada { background: #f5efe3; border: 1px solid #e4dac3; border-radius: .55rem; padding: .65rem .85rem; margin-block: .75rem; }
.ocupacion-copiada h2, .ocupacion-copiada p { margin: .3rem 0; }
.mes-sin-atencion { color: #59655e; }
.icono-loto { width: 1rem; height: 1rem; vertical-align: middle; }
.turnos li:focus { outline: 2px solid #916119; outline-offset: 1px; }
@media (max-width: 75rem) {
  .tablero { grid-template-columns: minmax(0, 1fr); grid-template-areas: "acciones" "calendario" "resumen"; grid-template-rows: auto; }
  .columna-resumen { display: flex; gap: .55rem; flex-wrap: wrap; align-items: start; }
  .columna-resumen .panel { margin-bottom: 0; }
  .panel.acciones-rapidas { display: flex; flex-wrap: wrap; }
  .acciones-rapidas > :is(button, a) { width: auto; }
  .columna-resumen section { flex: 1; }
  .resumen { display: flex; gap: .45rem .9rem; flex-wrap: wrap; }
}
@media (max-width: 42rem) {
  .cabecera-zenli { grid-template-columns: auto 1fr; }
  .marca-zenli { grid-column: 1; }
  .avatar { display: none; }
  .cuenta-zenli summary { padding-inline: .55rem; }
}
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
.asistencia { display: inline-block; width: fit-content; border-radius: .35rem; padding: .12rem .4rem; background: #eee8db; color: #3e493e; font-weight: 700; }
.asistencia.atendido { background: #dceadf; color: #143c2a; }
.asistencia.ausente { background: #f9e3df; color: #75271f; }
.resultados-turnos p { margin: .25rem 0; }
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
.calendario { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: .3rem; margin-block: .8rem 1rem; }
.celda { display: grid; align-content: start; gap: .25rem; width: 100%; min-height: 4rem; padding: .4rem; text-align: left; color: #15372a; border-color: #ddd4bd; background: #fffdf7; overflow-wrap: anywhere; }
.celda[aria-pressed=true] { outline: 2px solid #214d3a; outline-offset: -2px; }
.celda.propia, .turnos li.propio { background: #e5f2e6; }
.turnos li.propio { border-left: 4px solid #286644; padding-left: .5rem; }
.dia-semana { text-align: center; font-size: .8rem; font-weight: 700; }
.mio { display: inline-block; width: fit-content; border-radius: .25rem; padding: .1rem .25rem; background: #d2ead7; color: #134627; font-weight: 700; }
.turnos li.bloqueado { background: #f4e8d6; border-left: 4px solid #95642d; padding-left: .5rem; }
.marca-bloqueo { color: #61411b; font-weight: 700; }
.detalle-dia { margin-top: 1rem; }
.cabecera-detalle-dia { margin-top: 1rem; margin-bottom: .4rem; }
.boton-hoja-dia { font-size: .85rem; padding: .35rem .65rem; }
.boton-hoja-dia-rapida { background: #fffaf1; color: #174334; font-weight: 600; }
.boton-wa-turno {
  display: inline-flex;
  align-items: center;
  gap: .3rem;
  padding: .2rem .55rem;
  background: #25d366;
  color: #072a15;
  font-size: .8rem;
  font-weight: 600;
  border-radius: .35rem;
  text-decoration: none;
  border: 1px solid #1ebe5d;
  line-height: 1.2;
}
.boton-wa-turno:hover { background: #20ba59; color: #000; }
.boton-wa-turno--precaucion { background: #fce8e6; border-color: #f1b3ad; color: #8a2a20; }
.boton-wa-turno--precaucion:hover { background: #f8d5d1; color: #641911; }
.alerta-wa-no-contactar { font-weight: 800; margin-left: .15rem; }
.badge-no-contactar { display: inline-block; padding: .15rem .45rem; background: #fae2dc; color: #8e1c13; font-size: .75rem; font-weight: 700; border-radius: .3rem; margin-left: .45rem; vertical-align: middle; }
.modal-confirmar-wa { width: min(calc(100% - 1.5rem), 32rem); padding: 1.5rem; border: 2px solid #e27a6e; border-radius: .85rem; background: #fffbf9; color: #211210; box-shadow: 0 1rem 3rem rgba(80, 20, 10, .25); }
.caja-confirmar-wa { display: flex; flex-direction: column; gap: .9rem; text-align: center; }
.icono-alerta-wa { font-size: 2.5rem; line-height: 1; }
.caja-confirmar-wa h3 { margin: 0; font-size: 1.25rem; color: #8a2016; }
.advertencia-wa { margin: 0; font-size: .95rem; line-height: 1.4; color: #3b1915; }
.detalle-advertencia-wa { margin: 0; font-size: .85rem; line-height: 1.35; color: #69433e; background: #fdedea; padding: .65rem .85rem; border-radius: .4rem; border-left: 3px solid #d45b4e; text-align: left; }
.acciones-modal-wa { display: flex; flex-direction: column; gap: .6rem; margin-top: .5rem; }
.boton-forzar-wa { background: transparent; border: 1px solid #d45b4e; color: #8a2016; padding: .5rem .8rem; font-size: .85rem; border-radius: .45rem; cursor: pointer; transition: background .15s; }
.boton-forzar-wa:hover { background: #fbe6e3; }
.aviso-hoja-no-contactar { display: inline-block; font-size: .68rem; font-weight: 800; color: #8e1c13; background: #fae2dc; padding: .1rem .35rem; border-radius: .2rem; margin-top: .15rem; letter-spacing: .03em; }
.boton-telefono-turno { color: #214d3a; font-weight: 600; text-decoration: none; }
dialog { max-height: 88dvh; width: min(calc(100% - 1.5rem), 38rem); padding: 1.2rem; border: 1px solid #e4dac3; border-radius: .8rem; background: #fffaf1; color: #15372a; }
dialog::backdrop { background: #071b13a8; }
.resultado-disponibilidad { margin-top: 1rem; }
.resultado-disponibilidad h3 { font-size: .95rem; margin: .65rem 0 .3rem; }
.huecos { display: flex; flex-wrap: wrap; gap: .45rem; list-style: none; padding: 0; margin: .45rem 0; }
.huecos li { padding: .4rem .65rem; border-radius: .3rem; background: #e5f2e6; color: #15372a; font-variant-numeric: tabular-nums; }
dialog form { margin-top: .7rem; }

/* Estilo Hoja de Agenda física / Printable daily agenda */
.modal-hoja-dia {
  width: min(calc(100% - 2rem), 66rem) !important;
  max-height: 94dvh;
  padding: 1.2rem;
  background: #fdfaf3;
  color: #1a2a22;
  border: 1px solid #d4c7b0;
  border-radius: .8rem;
  box-shadow: 0 1rem 3rem rgba(11, 43, 32, .25);
  overflow-y: auto;
}
.barra-acciones-hoja {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: .8rem;
  padding-bottom: .8rem;
  margin-bottom: 1rem;
  border-bottom: 2px solid #e2d7c3;
}
.barra-acciones-hoja h2 { margin: 0; font-size: 1.25rem; }
.barra-acciones-hoja small { color: #625d52; }
.boton-imprimir-hoja {
  background: #174334;
  color: #fff9e7;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid #0f2e23;
  padding: .5rem .9rem;
  border-radius: .4rem;
}
.boton-imprimir-hoja:hover { background: #215a46; }

.hoja-papel-agenda {
  background: #fffdf9;
  border: 1px solid #e0d6c3;
  border-radius: .4rem;
  padding: 1.4rem;
  font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
  box-shadow: 0 2px 8px rgba(0,0,0,.03);
}
.encabezado-hoja {
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 3px double #d8bc78;
  padding-bottom: .8rem;
  margin-bottom: .9rem;
}
.logo-y-marca {
  display: flex;
  align-items: center;
  gap: .8rem;
}
.icono-loto-hoja {
  width: 2.4rem;
  height: 2.4rem;
}
.marca-zenli-hoja {
  margin: 0;
  font-family: Georgia, serif;
  font-size: 1.45rem;
  color: #0b2b20;
  letter-spacing: .08em;
}
.sede-zenli-hoja {
  margin: 0;
  font-size: .82rem;
  color: #796644;
  text-transform: uppercase;
  letter-spacing: .1em;
}
.caja-fecha-hoja {
  text-align: right;
  background: #fbf5e8;
  border: 1px solid #dfcfad;
  border-radius: .5rem;
  padding: .4rem .9rem;
  display: grid;
  justify-items: end;
}
.fecha-dia-semana {
  font-size: .85rem;
  font-weight: 700;
  color: #174334;
  text-transform: capitalize;
}
.fecha-dia-numero {
  font-size: 1.8rem;
  font-family: Georgia, serif;
  font-weight: 700;
  line-height: 1;
  color: #0b2b20;
}
.fecha-mes-ano {
  font-size: .78rem;
  color: #605747;
  text-transform: capitalize;
}

.franja-meta-hoja {
  display: flex;
  flex-wrap: wrap;
  gap: 1.2rem;
  background: #f6efe1;
  border-radius: .35rem;
  padding: .45rem .8rem;
  font-size: .82rem;
  margin-bottom: 1rem;
}
.meta-etiqueta { color: #5c5547; margin-right: .3rem; }
.meta-valor { color: #15372a; }
.meta-feriado { color: #8e4420; font-weight: 700; }

.tabla-hoja-agenda {
  width: 100%;
  border-collapse: collapse;
  font-size: .83rem;
  margin-bottom: 1.2rem;
}
.tabla-hoja-agenda th {
  background: #15372a;
  color: #fcf6e8;
  font-weight: 600;
  text-align: left;
  padding: .5rem .45rem;
  border: 1px solid #0f2a20;
  font-size: .78rem;
  text-transform: uppercase;
  letter-spacing: .04em;
}
.tabla-hoja-agenda td {
  padding: .5rem .45rem;
  border: 1px solid #ddd1ba;
  vertical-align: top;
  background: #fffdf9;
}
.tabla-hoja-agenda tbody tr:nth-child(even) td {
  background: #faf6ed;
}
.celda-hora { white-space: nowrap; font-variant-numeric: tabular-nums; font-weight: 700; color: #0b2b20; }
.minutos-duracion { display: block; color: #625c50; font-size: .75rem; }
.sena-si { color: #166534; font-weight: 600; }
.sena-no { color: #854d0e; }
.asistencia-impresa { font-weight: 600; color: #15372a; }
.casilleros-asistencia { font-size: .75rem; color: #5a5242; white-space: nowrap; }
.sin-turnos-hoja { text-align: center; padding: 1.8rem; font-style: italic; color: #6b6352; }
.fila-bloqueo td { background: #fdf2e4 !important; color: #78350f; font-style: italic; }
.fila-preparacion td { background: #f0f7f3 !important; color: #166534; font-style: italic; }

.celda-renglones {
  min-width: 9rem;
}
.linea-punteada {
  border-bottom: 1px dashed #d1c5ae;
  height: 1.1rem;
}

.pie-hoja-agenda {
  margin-top: 1rem;
  padding-top: .9rem;
  border-top: 2px solid #dfd3bd;
  font-size: .8rem;
}
.bloque-anotaciones h3 {
  font-size: .82rem;
  font-family: Georgia, serif;
  margin: 0 0 .4rem;
  color: #2b251a;
}
.renglon-cuaderno {
  border-bottom: 1px solid #e2d7c3;
  height: 1.4rem;
}
.bloque-cierre {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 1rem;
  margin-top: 1rem;
  padding-top: .6rem;
  color: #4f4738;
}
.checks-cierre { display: flex; gap: 1.2rem; font-weight: 600; }
.firma-responsable { font-family: Georgia, serif; }

/* Impresión física de la hoja del día */
@media print {
  body * {
    visibility: hidden !important;
  }
  .modal-hoja-dia, .modal-hoja-dia * {
    visibility: visible !important;
  }
  .modal-hoja-dia {
    position: absolute !important;
    left: 0 !important;
    top: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    border: none !important;
    box-shadow: none !important;
    background: #fff !important;
  }
  .hoja-papel-agenda {
    border: none !important;
    box-shadow: none !important;
    padding: 0 !important;
  }
  .no-imprimir {
    display: none !important;
  }
  @page {
    size: portrait;
    margin: 8mm;
  }
}

@media (max-width: 42rem) {
  .celda { font-size: .72rem; padding: .25rem; }
}
@media (max-width: 42rem) {
  .turnos li { grid-template-columns: 1fr; }
}
</style>
