<script setup>
import { computed, onMounted, ref } from 'vue'

const zona = 'America/Argentina/Mendoza'
const fecha = ref(new Intl.DateTimeFormat('sv-SE', { timeZone: zona }).format(new Date()))
const reloj = new Intl.DateTimeFormat('es-AR', { timeZone: zona, hour: '2-digit', minute: '2-digit' })
const datos = ref(null)
const csrf = ref('')
const ocupado = ref(false)
const error = ref('')
const aviso = ref('')
const configurar = ref(false)
const modo = ref('')
const minutos = ref('')
const porServicio = ref({})
const cliente = ref('')
const servicio = ref('')
const hora = ref('')
const duracion = ref('')
const espacio = ref('')
const equipo = ref([])
const varias = ref(false)
const preparacion = computed(() => {
  const ajustes = datos.value?.preparacion
  return ajustes?.modo === 'general' ? ajustes.minutos : ajustes?.servicios?.[servicio.value]
})

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
  datos.value = await api(`/api/admin/agenda?fecha=${encodeURIComponent(fecha.value)}`)
  if (datos.value.espacios.length === 1) espacio.value = datos.value.espacios[0].id
  if (datos.value.servicios.length === 1) servicio.value = datos.value.servicios[0].id
  if (!datos.value.preparacion) abrirConfiguracion()
}

function abrirConfiguracion() {
  const configuracion = datos.value?.preparacion
  modo.value = configuracion?.modo ?? ''
  minutos.value = configuracion?.minutos ?? ''
  porServicio.value = { ...configuracion?.servicios }
  configurar.value = true
}

function guardarPreparacion() {
  return ejecutar(async () => {
    const body = modo.value === 'general'
      ? { modo: modo.value, minutos: minutos.value }
      : { modo: modo.value, servicios: Object.fromEntries(Object.entries(porServicio.value).filter(([, valor]) => valor !== '')) }
    await api('/api/admin/agenda/preparacion', 'PUT', body)
    await cargarDia()
    configurar.value = false
    aviso.value = 'Preparación guardada.'
  })
}

function cambiarEquipo() {
  equipo.value = equipo.value.slice(0, 1)
}

function guardarTurno() {
  return ejecutar(async () => {
    if (varias.value && equipo.value.length < 2) throw new Error('Elegí al menos dos profesionales para un turno de equipo.')
    await api('/api/admin/agenda/turnos', 'POST', {
      cliente: cliente.value,
      servicio: servicio.value,
      espacio: espacio.value,
      profesionales: equipo.value,
      inicio: `${fecha.value}T${hora.value}`,
      duracion: duracion.value,
    })
    cliente.value = ''
    hora.value = ''
    await cargarDia()
    aviso.value = 'Turno guardado.'
  })
}

onMounted(() => ejecutar(async () => {
  document.title = 'Agenda · Zenli'
  const sesion = await api('/api/admin/sesion')
  if (!sesion.usuario) {
    window.location.replace('/admin')
    return
  }
  csrf.value = sesion.csrf
  await cargarDia()
}))
</script>

<template>
  <div class="agenda">
    <header><a href="/admin">← Administración</a><span>Zenli</span></header>
    <main>
      <h1>Agenda interna</h1>
      <p v-if="error" role="alert">{{ error }}</p>
      <p v-if="aviso" role="status">{{ aviso }}</p>
      <p v-if="!datos && ocupado" role="status">Cargando agenda…</p>

      <template v-if="datos">
        <section class="panel">
          <div class="barra">
            <h2>Turnos del día</h2>
            <label>Fecha <input v-model="fecha" type="date" :disabled="ocupado" @change="fecha && ejecutar(cargarDia)"></label>
          </div>
          <p v-if="!datos.turnos.length">Todavía no hay turnos cargados para este día.</p>
          <ul v-else class="turnos">
            <li v-for="turno in datos.turnos" :key="turno.id">
              <strong>{{ reloj.format(new Date(turno.inicio)) }}–{{ reloj.format(new Date(turno.fin)) }}</strong>
              <span>{{ turno.servicio }} · {{ turno.cliente }}</span>
              <small>{{ turno.espacio }} · {{ turno.profesionales }}</small>
            </li>
          </ul>
        </section>

        <div class="columnas">
          <section class="panel">
            <h2>Nuevo turno</h2>
            <p v-if="!datos.servicios.length">Creá un servicio en <a href="/admin">Administración</a> para cargar turnos.</p>
            <p v-else-if="!datos.profesionales.length || !datos.espacios.length">Hace falta al menos una profesional y un espacio activos.</p>
            <form v-else @submit.prevent="guardarTurno">
              <label>Servicio
                <select v-model="servicio" required>
                  <option disabled value="">Elegí un servicio</option>
                  <option v-for="item in datos.servicios" :key="item.id" :value="item.id">{{ item.nombre }}</option>
                </select>
              </label>
              <p v-if="servicio && preparacion == null">Configurá la preparación de este servicio para poder agendarlo.</p>
              <p v-else-if="servicio">Preparación antes del turno: {{ preparacion }} min.</p>
              <label>Nombre del cliente <input v-model="cliente" maxlength="150" required></label>
              <div class="barra">
                <label>Hora <input v-model="hora" type="time" required></label>
                <label>Duración (min) <input v-model.number="duracion" type="number" min="1" max="1440" required></label>
              </div>
              <label v-if="datos.espacios.length > 1">Espacio
                <select v-model="espacio" required>
                  <option disabled value="">Elegí un espacio</option>
                  <option v-for="item in datos.espacios" :key="item.id" :value="item.id">{{ item.nombre }}</option>
                </select>
              </label>
              <p v-else>Espacio: {{ datos.espacios[0].nombre }}</p>
              <label v-if="datos.profesionales.length > 1" class="opcion"><input v-model="varias" type="checkbox" @change="cambiarEquipo"> Participa más de una profesional</label>
              <fieldset v-if="varias">
                <legend>Profesionales</legend>
                <label v-for="persona in datos.profesionales" :key="persona.id" class="opcion">
                  <input v-model="equipo" type="checkbox" :value="persona.id"> {{ persona.nombre }}
                </label>
              </fieldset>
              <label v-else>Profesional
                <select :value="equipo[0] ?? ''" required @change="equipo = [$event.target.value]">
                  <option disabled value="">Elegí una profesional</option>
                  <option v-for="persona in datos.profesionales" :key="persona.id" :value="persona.id">{{ persona.nombre }}</option>
                </select>
              </label>
              <button :disabled="ocupado || !fecha || preparacion == null || (varias && equipo.length < 2)">Cargar turno</button>
            </form>
          </section>

          <section class="panel">
            <h2>Preparación</h2>
            <button v-if="!configurar" type="button" @click="abrirConfiguracion">Editar preparación</button>
            <form v-else @submit.prevent="guardarPreparacion">
              <fieldset>
                <legend>¿Todos los servicios tienen el mismo tiempo de preparación?</legend>
                <label class="opcion"><input v-model="modo" type="radio" value="general" required> Sí, el mismo para todos</label>
                <label class="opcion"><input v-model="modo" type="radio" value="por_servicio" required> No, se define por servicio</label>
              </fieldset>
              <label v-if="modo === 'general'">Minutos de preparación <input v-model.number="minutos" type="number" min="0" max="1440" required></label>
              <template v-if="modo === 'por_servicio'">
                <p>Dejá sin completar los servicios que todavía no querés agendar.</p>
                <label v-for="item in datos.servicios" :key="item.id">
                  {{ item.nombre }} (min) <input v-model.number="porServicio[item.id]" type="number" min="0" max="1440">
                </label>
              </template>
              <button :disabled="ocupado || !modo">Guardar preparación</button>
            </form>
          </section>
        </div>
      </template>
    </main>
  </div>
</template>

<style scoped>
.agenda { min-height: 100vh; background: #f8f3e7; color: #15372a; }
header { display: flex; justify-content: space-between; padding: 1rem max(1rem, (100% - 70rem) / 2); background: #08271c; color: #f4dfb2; }
header a { color: inherit; }
main { width: min(100% - 2rem, 70rem); margin: auto; padding-block: 2rem; }
h1, h2 { font-family: Georgia, serif; }
h1 { margin-top: 0; }
h2, p { margin-block: 0 .6rem; }
.panel { padding: 1rem; background: #fffaf1; border: 1px solid #e4dac3; border-radius: .9rem; margin-bottom: 1rem; }
.columnas { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; align-items: start; }
.barra { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; justify-content: space-between; }
form, label { display: grid; gap: .35rem; }
form { gap: .8rem; }
.opcion { display: flex; gap: .5rem; align-items: center; margin-block: .3rem; }
fieldset { border: 1px solid #e4dac3; border-radius: .4rem; min-width: 0; }
input, select, button { font: inherit; }
input:not([type=checkbox], [type=radio]), select { min-width: 0; padding: .5rem; border: 1px solid #a4ad9f; border-radius: .35rem; background: #fffdf7; }
button { width: fit-content; padding: .6rem .8rem; border: 1px solid #214d3a; border-radius: .4rem; background: #214d3a; color: white; }
button:disabled { opacity: .55; }
.turnos { list-style: none; padding: 0; margin: 0; }
.turnos li { display: grid; grid-template-columns: 7rem 1fr 1fr; gap: .6rem; padding-block: .65rem; border-top: 1px solid #e4dac3; }
[role=alert] { background: #f9e3df; padding: .65rem; }
[role=status] { padding: .45rem; }
@media (max-width: 42rem) {
  .columnas, .turnos li { grid-template-columns: 1fr; }
}
</style>
