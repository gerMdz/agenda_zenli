<script setup>
import { computed, onMounted, ref } from 'vue'

const zona = 'America/Argentina/Mendoza'
const fecha = ref(new Intl.DateTimeFormat('sv-SE', { timeZone: zona }).format(new Date()))
const calendario = new Intl.DateTimeFormat('sv-SE', { timeZone: zona })
const reloj = new Intl.DateTimeFormat('es-AR', { timeZone: zona, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
const datos = ref(null)
const csrf = ref('')
const usuario = ref(null)
const editando = ref(null)
const fechaTurno = ref('')
const bandeja = ref({ contactos: [], mensajes: [] })
const destinatario = ref('')
const texto = ref('')
const cuenta = ref({ profesional: '', email: '', clave: '' })
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
  if (!usuario.value.admin && !editando.value) equipo.value = [usuario.value.profesional_id]
  if (usuario.value.admin && !datos.value.preparacion) abrirConfiguracion()
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

function editarTurno(turno) {
  editando.value = turno
  const inicio = new Date(turno.inicio)
  fechaTurno.value = calendario.format(inicio)
  hora.value = reloj.format(inicio)
  cliente.value = turno.cliente
  servicio.value = turno.servicio_id ?? ''
  duracion.value = Number(turno.duracion)
  espacio.value = turno.espacio_id
  equipo.value = [...turno.equipo]
  varias.value = equipo.value.length > 1
}

function limpiarTurno() {
  editando.value = null
  cliente.value = ''
  hora.value = ''
  duracion.value = ''
  varias.value = false
  equipo.value = usuario.value.admin ? [] : [usuario.value.profesional_id]
  espacio.value = datos.value.espacios.length === 1 ? datos.value.espacios[0].id : ''
}

function guardarTurno() {
  return ejecutar(async () => {
    const anterior = editando.value
    if (varias.value && equipo.value.length < 2) throw new Error('Elegí al menos dos profesionales para un turno de equipo.')
    await api(anterior ? `/api/admin/agenda/turnos/${anterior.id}` : '/api/admin/agenda/turnos', anterior ? 'PUT' : 'POST', {
      cliente: cliente.value,
      servicio: servicio.value,
      espacio: espacio.value,
      profesionales: equipo.value,
      inicio: `${anterior ? fechaTurno.value : fecha.value}T${hora.value}`,
      duracion: duracion.value,
      revision: anterior ? Number(anterior.revision) : undefined,
    })
    if (anterior) fecha.value = fechaTurno.value
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

function enviarMensaje() {
  return ejecutar(async () => {
    bandeja.value = await api('/api/admin/agenda/mensajes', 'POST', { destinatario: destinatario.value, texto: texto.value })
    texto.value = ''
    aviso.value = 'Mensaje enviado.'
  })
}

function crearCuenta() {
  return ejecutar(async () => {
    await api('/api/admin/agenda/cuentas-profesionales', 'POST', cuenta.value)
    cuenta.value = { profesional: '', email: '', clave: '' }
    await cargarDia()
    bandeja.value = await api('/api/admin/agenda/mensajes')
    aviso.value = 'Cuenta profesional creada.'
  })
}

function salir() {
  return ejecutar(async () => {
    await api('/api/admin/sesion', 'DELETE')
    window.location.replace('/admin')
  })
}

onMounted(() => ejecutar(async () => {
  document.title = 'Agenda · Zenli'
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
      <a v-if="usuario?.admin" href="/admin">← Administración</a>
      <span>Zenli</span>
      <button v-if="usuario" type="button" :disabled="ocupado" @click="salir">Cerrar sesión</button>
    </header>
    <main>
      <h1>Agenda interna</h1>
      <p v-if="error" role="alert">{{ error }}</p>
      <p v-if="aviso" role="status">{{ aviso }}</p>
      <p v-if="!datos && ocupado" role="status">Cargando agenda…</p>

      <template v-if="datos">
        <details v-if="usuario.admin" class="panel" :open="!datos.preparacion">
          <summary>Guía para empezar</summary>
          <ol>
            <li><strong>Espacios.</strong> Confirmá dónde se atiende. Si hay uno activo, se elige automáticamente.</li>
            <li><strong>Profesionales.</strong> Usá las profesionales activas y creales una cuenta abajo si van a cargar su propia agenda.</li>
            <li><strong>Servicios.</strong> Cargalos en <a href="/admin">Administración</a> y definí acá su tiempo de preparación.</li>
            <li><strong>Turnos.</strong> Cargá fecha, hora y duración. Administración asigna los turnos de equipo.</li>
          </ol>
          <p>{{ datos.espacios.length }} espacios · {{ datos.profesionales.length }} profesionales · {{ datos.servicios.length }} servicios</p>
        </details>
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
              <div v-if="turno.editable" class="acciones">
                <button type="button" :disabled="ocupado" @click="editarTurno(turno)">Editar</button>
                <button type="button" class="secundario" :disabled="ocupado" @click="cancelarTurno(turno)">Cancelar turno</button>
              </div>
              <small v-else>Turno compartido · lo administra el equipo de administración</small>
            </li>
          </ul>
        </section>

        <div class="columnas">
          <section class="panel">
            <h2>{{ editando ? 'Editar turno' : 'Nuevo turno' }}</h2>
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
              <label v-if="editando">Fecha del turno <input v-model="fechaTurno" type="date" required></label>
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
              <label v-if="usuario.admin && datos.profesionales.length > 1" class="opcion"><input v-model="varias" type="checkbox" @change="cambiarEquipo"> Participa más de una profesional</label>
              <fieldset v-if="varias">
                <legend>Profesionales</legend>
                <label v-for="persona in datos.profesionales" :key="persona.id" class="opcion">
                  <input v-model="equipo" type="checkbox" :value="persona.id"> {{ persona.nombre }}
                </label>
              </fieldset>
              <label v-else-if="usuario.admin">Profesional
                <select :value="equipo[0] ?? ''" required @change="equipo = [$event.target.value]">
                  <option disabled value="">Elegí una profesional</option>
                  <option v-for="persona in datos.profesionales" :key="persona.id" :value="persona.id">{{ persona.nombre }}</option>
                </select>
              </label>
              <button :disabled="ocupado || !fecha || preparacion == null || (varias && equipo.length < 2)">{{ editando ? 'Guardar cambios' : 'Cargar turno' }}</button>
              <button v-if="editando" type="button" class="secundario" :disabled="ocupado" @click="limpiarTurno">Descartar edición</button>
            </form>
          </section>

          <section v-if="usuario.admin" class="panel">
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

        <div class="columnas">
          <section class="panel">
            <div class="barra">
              <h2>Mensajes privados</h2>
              <button type="button" class="secundario" :disabled="ocupado" @click="ejecutar(async () => { bandeja = await api('/api/admin/agenda/mensajes') })">Actualizar</button>
            </div>
            <form v-if="bandeja.contactos.length" @submit.prevent="enviarMensaje">
              <label>Para
                <select v-model="destinatario" required>
                  <option disabled value="">Elegí un contacto</option>
                  <option v-for="contacto in bandeja.contactos" :key="contacto.id" :value="contacto.id">{{ contacto.nombre }}</option>
                </select>
              </label>
              <label>Mensaje <textarea v-model="texto" maxlength="2000" rows="3" required></textarea></label>
              <button :disabled="ocupado">Enviar mensaje</button>
            </form>
            <p v-if="!bandeja.mensajes.length">Todavía no hay mensajes.</p>
            <ul v-else class="mensajes">
              <li v-for="mensaje in bandeja.mensajes" :key="mensaje.id">
                <strong>{{ Number(mensaje.enviado) ? 'Para' : 'De' }} {{ mensaje.contacto }}</strong>
                <p>{{ mensaje.texto }}</p>
              </li>
            </ul>
          </section>

          <section v-if="usuario.admin" class="panel">
            <h2>Cuentas de profesionales</h2>
            <p v-if="!datos.profesionales.some(persona => !Number(persona.tiene_cuenta))">Las profesionales activas ya tienen cuenta.</p>
            <form v-else @submit.prevent="crearCuenta">
              <label>Profesional
                <select v-model="cuenta.profesional" required>
                  <option disabled value="">Elegí una profesional</option>
                  <option v-for="persona in datos.profesionales.filter(item => !Number(item.tiene_cuenta))" :key="persona.id" :value="persona.id">{{ persona.nombre }}</option>
                </select>
              </label>
              <label>Correo <input v-model="cuenta.email" type="email" maxlength="180" autocomplete="off" required></label>
              <label>Contraseña inicial <input v-model="cuenta.clave" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></label>
              <button :disabled="ocupado">Crear cuenta profesional</button>
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
input, select, textarea, button { font: inherit; }
input:not([type=checkbox], [type=radio]), select, textarea { min-width: 0; padding: .5rem; border: 1px solid #a4ad9f; border-radius: .35rem; background: #fffdf7; }
button { width: fit-content; padding: .6rem .8rem; border: 1px solid #214d3a; border-radius: .4rem; background: #214d3a; color: white; }
button:disabled { opacity: .55; }
.turnos { list-style: none; padding: 0; margin: 0; }
.turnos li { display: grid; grid-template-columns: 7rem 1fr 1fr auto; gap: .6rem; padding-block: .65rem; border-top: 1px solid #e4dac3; }
.secundario { background: #fffaf1; color: #214d3a; }
.acciones { display: flex; flex-wrap: wrap; gap: .4rem; }
.mensajes { list-style: none; padding: 0; }
.mensajes li { padding-top: .5rem; border-top: 1px solid #e4dac3; }
.mensajes p { white-space: pre-wrap; overflow-wrap: anywhere; }
summary { cursor: pointer; font-weight: 700; }
[role=alert] { background: #f9e3df; padding: .65rem; }
[role=status] { padding: .45rem; }
@media (max-width: 42rem) {
  .columnas, .turnos li { grid-template-columns: 1fr; }
}
</style>
