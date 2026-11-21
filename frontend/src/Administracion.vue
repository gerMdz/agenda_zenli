<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import portadaOriginal from './image/portada.webp'
import LayoutAdministracion from './LayoutAdministracion.vue'
import EntradaHora from './EntradaHora.vue'
import { secciones } from './navegacionAdministracion.js'

function seccionDeUrl() {
  const id = window.location.hash.slice(1)
  return secciones.find(seccion => seccion.id === id) ?? secciones[0]
}

const seccionActiva = ref(seccionDeUrl())
const contenidoPrincipal = ref(null)

async function actualizarSeccion() {
  seccionActiva.value = seccionDeUrl()
  await nextTick()
  contenidoPrincipal.value?.focus({ preventScroll: true })
  window.scrollTo({ top: 0, behavior: 'instant' })
}

const usuario = ref(null)
const csrf = ref('')
const cargando = ref(true)
const ocupado = ref(false)
const mensaje = ref('')
const error = ref('')
const email = ref('')
const clave = ref('')
const telefono = ref('')
const direccion = ref('')
const horarios = ref('')
const instagram = ref('')
const facebook = ref('')
const soloImagen = ref(false)
const imagenGuardada = ref(null)
const imagenTemporal = ref(null)
const entradaArchivo = ref(null)
const restaurar = ref(false)
const usuarios = ref([])
const altaEmail = ref('')
const altaClave = ref('')
const altaProfesional = ref(false)
const altaPerfil = ref('')
const altaNombre = ref('')
const ajustesAgenda = ref(null)
const modoPreparacion = ref('')
const minutosPreparacion = ref('')
const preparacionServicios = ref({})
const cuenta = ref({ profesional: '', nombre: '', email: '', clave: '', admin: false, cuenta_existente: false })
const nombreProfesional = ref('')
const dialogoProfesional = ref(null)
const accionProfesional = ref('')
const espacioEditado = ref(null)
const espacioNombre = ref('')
const espacioDetalle = ref('')
const profesionalHorario = ref('')
const horarioProfesional = ref(null)
const diasSemana = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo']
const fechaMendoza = new Intl.DateTimeFormat('sv-SE', { timeZone: 'America/Argentina/Mendoza' })
const perfilesSinCuenta = computed(() => (ajustesAgenda.value?.profesionales ?? []).filter(item => !Number(item.tiene_cuenta)))
const usuarioEditado = ref(null)
const editarActivo = ref(true)
const editarClave = ref('')
const vistaPrevia = computed(() =>
  imagenTemporal.value || (!restaurar.value && imagenGuardada.value) || portadaOriginal,
)

// Servicios de portada
const servicios = ref([])
const servicioCreando = ref(false)
const nuevoNombre = ref('')
const nuevoDescCorta = ref('')
const nuevoDetalle = ref('')
const nuevoArchivo = ref(null)
const servicioEditando = ref(null)
const editNombre = ref('')
const editDescCorta = ref('')
const editDetalle = ref('')
const editMinimo = ref('')
const editProfesionales = ref('')
const editArchivo = ref(null)
const editEliminarImagen = ref(false)
const servicioImagenTemporal = ref(null)
const imagenNuevaTemporal = ref(null)
const dialogoServicio = ref(null)
const entradaImagenEdicion = ref(null)
const editarMensaje = ref('')
const crearError = ref('')
const editarError = ref('')

async function api(ruta, { method = 'GET', datos } = {}) {
  const headers = { Accept: 'application/json' }
  if (method !== 'GET') headers['X-Zenli-CSRF'] = csrf.value
  if (datos && !(datos instanceof FormData)) headers['Content-Type'] = 'application/json'

  const respuesta = await fetch(ruta, {
    method,
    headers,
    body: datos instanceof FormData ? datos : JSON.stringify(datos),
  })
  const contenido = respuesta.headers.get('content-type')?.includes('application/json')
    ? await respuesta.json()
    : null

  if (!respuesta.ok) {
    if (respuesta.status === 401 && ruta !== '/api/admin/sesion') await cargarSesion()
    const errores = {
      401: 'Ingresá a tu cuenta.',
      403: 'La sesión del formulario venció. Recargá la página.',
      413: 'La imagen supera los 4 MB. Elegí una más pequeña o comprimila.',
      429: 'Demasiados intentos. Probá nuevamente en unos minutos.',
    }
    throw new Error(contenido?.error || errores[respuesta.status] || 'No se pudo completar la operación.')
  }

  return contenido
}

function aplicarSesion(sesion) {
  usuario.value = sesion.usuario
  csrf.value = sesion.csrf
  if (!sesion.usuario) {
    usuarios.value = []
    usuarioEditado.value = null
    editarClave.value = ''
    altaClave.value = ''
    cuenta.value.clave = ''
    ajustesAgenda.value = null
    horarioProfesional.value = null
    profesionalHorario.value = ''
    limpiarImagenTemporal()
  }
}

async function cargarSesion() {
  aplicarSesion(await api('/api/admin/sesion'))
}

async function cargarPanel() {
  if (usuario.value && !usuario.value.admin) {
    window.location.replace('/admin/agenda')
    return
  }
  const [portada, listado, svcs, ajustes] = await Promise.all([
    api('/api/portada'),
    api('/api/admin/usuarios'),
    api('/api/admin/servicios'),
    api('/api/admin/agenda/configuracion'),
  ])
  aplicarPortada(portada)
  usuarios.value = listado.usuarios
  servicios.value = svcs.servicios
  aplicarConfiguracionAgenda(ajustes)
  const perfil = new URLSearchParams(window.location.search).get('profesional')
  if (seccionActiva.value.id === 'horarios-profesionales' && ajustes.profesionales.some(persona => persona.id === perfil)) {
    profesionalHorario.value = perfil
    aplicarHorario(await api(`/api/admin/agenda/profesionales/${perfil}/horarios`))
  }
}

function aplicarPortada(portada) {
  telefono.value = portada.telefono
  direccion.value = portada.direccion ?? ''
  horarios.value = portada.horarios ?? ''
  instagram.value = portada.instagram ?? ''
  facebook.value = portada.facebook ?? ''
  soloImagen.value = portada.solo_imagen === true
  imagenGuardada.value = portada.imagen
  restaurar.value = false
}

async function ejecutar(accion) {
  if (ocupado.value) return
  ocupado.value = true
  error.value = ''
  mensaje.value = ''
  try {
    await accion()
  } catch (fallo) {
    error.value = fallo.message
  } finally {
    ocupado.value = false
  }
}

onMounted(async () => {
  document.title = 'Administración · Zenli'
  window.addEventListener('hashchange', actualizarSeccion)
  await ejecutar(async () => {
    await cargarSesion()
    if (usuario.value) await cargarPanel()
  })
  cargando.value = false
})

function ingresar() {
  return ejecutar(async () => {
    try {
      aplicarSesion(await api('/api/admin/sesion', { method: 'POST', datos: { email: email.value, clave: clave.value } }))
      await cargarPanel()
    } finally {
      clave.value = ''
    }
  })
}

function salir() {
  return ejecutar(async () => {
    aplicarSesion(await api('/api/admin/sesion', { method: 'DELETE' }))
    email.value = ''
  })
}

function limpiarImagenTemporal() {
  if (imagenTemporal.value) URL.revokeObjectURL(imagenTemporal.value)
  imagenTemporal.value = null
}

function elegirImagen(evento) {
  limpiarImagenTemporal()
  restaurar.value = false
  error.value = ''
  const archivo = evento.target.files[0]
  if (!archivo) return
  if (archivo.size > 4 * 1024 * 1024) {
    error.value = 'La imagen supera los 4 MB.'
    evento.target.value = ''
    return
  }
  imagenTemporal.value = URL.createObjectURL(archivo)
}

function restaurarImagen() {
  limpiarImagenTemporal()
  entradaArchivo.value.value = ''
  restaurar.value = true
}

function cambiarModoPortada() {
  return ejecutar(async () => {
    const modo = await api('/api/admin/portada/modo', {
      method: 'PATCH',
      datos: { solo_imagen: !soloImagen.value },
    })
    soloImagen.value = modo.solo_imagen === true
    mensaje.value = soloImagen.value
      ? 'Sitio bajado. La portada pública muestra solo la imagen.'
      : 'Sitio subido. La portada completa está visible.'
  })
}

function guardarPortada(evento) {
  const datos = new FormData(evento.currentTarget)
  if (!entradaArchivo.value.files.length) datos.delete('imagen')
  if (restaurar.value) datos.set('restaurar', '1')

  return ejecutar(async () => {
    const portada = await api('/api/admin/portada', { method: 'POST', datos })
    aplicarPortada(portada)
    entradaArchivo.value.value = ''
    limpiarImagenTemporal()
    mensaje.value = 'Portada actualizada.'
  })
}

function aplicarConfiguracionAgenda(ajustes) {
  ajustesAgenda.value = ajustes
  modoPreparacion.value = ajustes.preparacion?.modo ?? ''
  minutosPreparacion.value = ajustes.preparacion?.minutos ?? ''
  preparacionServicios.value = { ...ajustes.preparacion?.servicios }
}

async function recargarEquipo() {
  const [ajustes, listado] = await Promise.all([
    api('/api/admin/agenda/configuracion'),
    api('/api/admin/usuarios'),
  ])
  ajustesAgenda.value = ajustes
  usuarios.value = listado.usuarios
}

function crearUsuario() {
  return ejecutar(async () => {
    const datos = { email: altaEmail.value, clave: altaClave.value }
    if (altaProfesional.value) {
      await api('/api/admin/agenda/cuentas-profesionales', {
        method: 'POST',
        datos: {
          ...datos, admin: true, profesional: altaPerfil.value === 'nueva' ? null : altaPerfil.value,
          nombre: altaNombre.value,
        },
      })
      await recargarEquipo()
    } else {
      usuarios.value = (await api('/api/admin/usuarios', { method: 'POST', datos })).usuarios
    }
    altaEmail.value = ''
    altaClave.value = ''
    altaProfesional.value = false
    altaPerfil.value = ''
    altaNombre.value = ''
    mensaje.value = 'Cuenta administradora creada.'
  })
}

function crearProfesional() {
  return ejecutar(async () => {
    await api('/api/admin/agenda/profesionales', { method: 'POST', datos: { nombre: nombreProfesional.value } })
    nombreProfesional.value = ''
    await recargarEquipo()
    cerrarDialogoProfesional()
    mensaje.value = 'Profesional creada sin cuenta.'
  })
}

function crearCuentaProfesional() {
  return ejecutar(async () => {
    await api('/api/admin/agenda/cuentas-profesionales', {
      method: 'POST',
      datos: { ...cuenta.value, profesional: cuenta.value.profesional === 'nueva' ? null : cuenta.value.profesional },
    })
    const vinculo = cuenta.value.cuenta_existente
    cuenta.value = { profesional: '', nombre: '', email: '', clave: '', admin: false, cuenta_existente: false }
    await recargarEquipo()
    cerrarDialogoProfesional()
    mensaje.value = vinculo ? 'Cuenta vinculada.' : 'Cuenta profesional creada.'
  })
}

async function abrirDialogoProfesional(accion, profesional = '') {
  error.value = ''
  mensaje.value = ''
  accionProfesional.value = accion
  cuenta.value.profesional = profesional
  await nextTick()
  dialogoProfesional.value.showModal()
}

function cerrarDialogoProfesional() {
  if (dialogoProfesional.value?.open) dialogoProfesional.value.close()
  accionProfesional.value = ''
  nombreProfesional.value = ''
  cuenta.value = { profesional: '', nombre: '', email: '', clave: '', admin: false, cuenta_existente: false }
  error.value = ''
}

function guardarPreparacionAgenda() {
  return ejecutar(async () => {
    const datos = modoPreparacion.value === 'general'
      ? { modo: modoPreparacion.value, minutos: minutosPreparacion.value }
      : { modo: modoPreparacion.value, servicios: Object.fromEntries(Object.entries(preparacionServicios.value).filter(([, valor]) => valor !== '')) }
    await api('/api/admin/agenda/preparacion', { method: 'PUT', datos })
    aplicarConfiguracionAgenda(await api('/api/admin/agenda/configuracion'))
    mensaje.value = 'Preparación guardada.'
  })
}

function aplicarHorario(contenido) {
  horarioProfesional.value = {
    ...contenido,
    excepciones: (contenido.excepciones ?? []).map(fila => ({
      ...fila,
      todo_el_dia: fila.tipo === 'bloqueo' && fila.hora_inicio === '00:00:00' && fila.hora_fin === '24:00:00',
      hora_inicio: fila.hora_inicio.replace(/^(\d{2}:\d{2}):00$/, '$1'),
      hora_fin: fila.hora_fin.replace(/^(\d{2}:\d{2}):00$/, '$1'),
    })),
    franjas: contenido.franjas.map(fila => ({
      ...fila,
      tipo: fila.vigente_desde === fila.vigente_hasta ? 'puntual' : 'semanal',
      dia_semana: Number(fila.dia_semana),
      hora_inicio: fila.hora_inicio.replace(/^(\d{2}:\d{2}):00$/, '$1'),
      hora_fin: fila.hora_fin.replace(/^(\d{2}:\d{2}):00$/, '$1'),
      vigente_hasta: fila.vigente_hasta ?? '',
    })),
  }
}

function cargarHorario() {
  horarioProfesional.value = null
  if (!profesionalHorario.value) return
  return ejecutar(async () => {
    aplicarHorario(await api(`/api/admin/agenda/profesionales/${profesionalHorario.value}/horarios`))
  })
}

function agregarFranja(tipo) {
  horarioProfesional.value.por_franjas = true
  horarioProfesional.value.franjas.push({
    tipo,
    dia_semana: '',
    vigente_desde: tipo === 'semanal' ? fechaMendoza.format(new Date()) : '',
    vigente_hasta: '',
    hora_inicio: '',
    hora_fin: '',
  })
}

function agregarExcepcion(tipo) {
  horarioProfesional.value.excepciones.push({
    tipo, fecha: '', hora_inicio: '', hora_fin: '', todo_el_dia: false,
  })
}

function guardarHorario() {
  return ejecutar(async () => {
    aplicarHorario(await api(`/api/admin/agenda/profesionales/${profesionalHorario.value}/horarios`, {
      method: 'PUT', datos: horarioProfesional.value,
    }))
    mensaje.value = 'Horarios guardados.'
  })
}

function editarEspacio(espacio = null) {
  espacioEditado.value = espacio?.id ?? null
  espacioNombre.value = espacio?.nombre ?? ''
  espacioDetalle.value = espacio?.detalle ?? ''
}

function guardarEspacio() {
  return ejecutar(async () => {
    const id = espacioEditado.value
    ajustesAgenda.value = await api(id ? `/api/admin/agenda/espacios/${id}` : '/api/admin/agenda/espacios', {
      method: id ? 'PUT' : 'POST',
      datos: { nombre: espacioNombre.value, detalle: espacioDetalle.value },
    })
    editarEspacio()
    mensaje.value = id ? 'Espacio actualizado.' : 'Espacio creado.'
  })
}

function seleccionarUsuario(cuenta) {
  usuarioEditado.value = cuenta
  editarActivo.value = cuenta.activo
  editarClave.value = ''
}

function guardarUsuario() {
  return ejecutar(async () => {
    const id = usuarioEditado.value.id
    const renovarSesion = id === usuario.value.id && editarClave.value !== ''
    const listado = await api(`/api/admin/usuarios/${id}`, {
      method: 'PATCH',
      datos: { activo: editarActivo.value, clave: editarClave.value },
    })
    usuarios.value = listado.usuarios
    usuarioEditado.value = null
    editarClave.value = ''
    if (renovarSesion) await cargarSesion()
    mensaje.value = renovarSesion ? 'Contraseña actualizada. Ingresá nuevamente.' : 'Cuenta actualizada.'
  })
}

// --- Servicios de portada ---

function iniciarCreacion() {
  servicioCreando.value = true
  nuevoNombre.value = ''
  nuevoDescCorta.value = ''
  nuevoDetalle.value = ''
  nuevoArchivo.value = null
  imagenNuevaTemporal.value = null
  crearError.value = ''
  error.value = ''
}

function cancelarCreacion() {
  servicioCreando.value = false
  nuevoNombre.value = ''
  nuevoDescCorta.value = ''
  nuevoDetalle.value = ''
  nuevoArchivo.value = null
  if (imagenNuevaTemporal.value) URL.revokeObjectURL(imagenNuevaTemporal.value)
  imagenNuevaTemporal.value = null
  crearError.value = ''
}

function elegirImagenServicio(evento) {
  const archivo = evento.target.files[0]
  if (!archivo) return
  if (archivo.size > 4 * 1024 * 1024) {
    crearError.value = 'La imagen supera los 4 MB.'
    evento.target.value = ''
    return
  }
  crearError.value = ''
  if (imagenNuevaTemporal.value) URL.revokeObjectURL(imagenNuevaTemporal.value)
  imagenNuevaTemporal.value = URL.createObjectURL(archivo)
  nuevoArchivo.value = archivo
}

async function crearServicio() {
  crearError.value = ''
  if (ocupado.value) return
  ocupado.value = true
  try {
    const datos = new FormData()
    datos.set('nombre', nuevoNombre.value)
    datos.set('descripcion_corta', nuevoDescCorta.value)
    datos.set('detalle', nuevoDetalle.value)
    if (nuevoArchivo.value) datos.set('imagen', nuevoArchivo.value)
    const resultado = await api('/api/admin/servicios', { method: 'POST', datos })
    servicios.value = resultado.servicios
    cancelarCreacion()
    mensaje.value = 'Servicio creado.'
  } catch (fallo) {
    crearError.value = fallo.message
  } finally {
    ocupado.value = false
  }
}

async function iniciarEdicion(servicio) {
  servicioEditando.value = servicio
  editNombre.value = servicio.nombre
  editDescCorta.value = servicio.descripcion_corta
  editDetalle.value = servicio.detalle
  editMinimo.value = servicio.duracion_minima ?? ''
  editProfesionales.value = servicio.profesionales_requeridos ?? ''
  editArchivo.value = null
  editEliminarImagen.value = false
  if (servicioImagenTemporal.value) URL.revokeObjectURL(servicioImagenTemporal.value)
  servicioImagenTemporal.value = null
  editarError.value = ''
  editarMensaje.value = ''
  error.value = ''
  mensaje.value = ''
  await nextTick()
  dialogoServicio.value.showModal()
  document.getElementById('edit-nombre')?.focus()
}

function cancelarEdicion() {
  if (dialogoServicio.value?.open) dialogoServicio.value.close()
  servicioEditando.value = null
  editNombre.value = ''
  editDescCorta.value = ''
  editDetalle.value = ''
  editArchivo.value = null
  editEliminarImagen.value = false
  if (servicioImagenTemporal.value) URL.revokeObjectURL(servicioImagenTemporal.value)
  servicioImagenTemporal.value = null
  editarError.value = ''
  editarMensaje.value = ''
}

function elegirImagenEdicion(evento) {
  const archivo = evento.target.files[0]
  if (!archivo) return
  if (archivo.size > 4 * 1024 * 1024) {
    editarError.value = 'La imagen supera los 4 MB.'
    evento.target.value = ''
    return
  }
  editarError.value = ''
  if (servicioImagenTemporal.value) URL.revokeObjectURL(servicioImagenTemporal.value)
  servicioImagenTemporal.value = URL.createObjectURL(archivo)
  editArchivo.value = archivo
  editEliminarImagen.value = false
}

async function guardarEdicion() {
  editarError.value = ''
  editarMensaje.value = ''
  if (ocupado.value) return
  ocupado.value = true
  try {
    const datos = new FormData()
    datos.set('nombre', editNombre.value)
    datos.set('descripcion_corta', editDescCorta.value)
    datos.set('detalle', editDetalle.value)
    if (editArchivo.value) datos.set('imagen', editArchivo.value)
    if (editEliminarImagen.value) datos.set('eliminar_imagen', '1')
    const resultado = await api(`/api/admin/servicios/${servicioEditando.value.id}`, { method: 'POST', datos })
    servicios.value = resultado.servicios
    servicioEditando.value = resultado.servicios.find(servicio => servicio.id === servicioEditando.value.id)
    editArchivo.value = null
    editEliminarImagen.value = false
    if (entradaImagenEdicion.value) entradaImagenEdicion.value.value = ''
    if (servicioImagenTemporal.value) URL.revokeObjectURL(servicioImagenTemporal.value)
    servicioImagenTemporal.value = null
    editarMensaje.value = 'Datos del servicio guardados.'
  } catch (fallo) {
    editarError.value = fallo.message
  } finally {
    ocupado.value = false
  }
}

async function guardarConfiguracionServicio() {
  if (ocupado.value) return
  ocupado.value = true
  editarError.value = ''
  editarMensaje.value = ''
  try {
    const resultado = await api(`/api/admin/servicios/${servicioEditando.value.id}/configuracion`, {
      method: 'PATCH',
      datos: {
        duracion_minima: editMinimo.value === '' ? null : Number(editMinimo.value),
        profesionales_requeridos: editProfesionales.value === '' ? null : Number(editProfesionales.value),
      },
    })
    servicios.value = resultado.servicios
    editarMensaje.value = 'Configuración de agenda guardada.'
  } catch (fallo) {
    editarError.value = fallo.message
  } finally {
    ocupado.value = false
  }
}

async function eliminarServicio(id) {
  if (!confirm('¿Eliminar este servicio? Esta acción no se puede deshacer.')) return
  return ejecutar(async () => {
    const resultado = await api(`/api/admin/servicios/${id}`, { method: 'DELETE' })
    servicios.value = resultado.servicios
    mensaje.value = 'Servicio eliminado.'
  })
}

async function cambiarVisibilidad(id, visible) {
  return ejecutar(async () => {
    const resultado = await api(`/api/admin/servicios/${id}/visible`, {
      method: 'PATCH',
      datos: { visible },
    })
    servicios.value = resultado.servicios
    mensaje.value = visible ? 'Servicio visible.' : 'Servicio oculto.'
  })
}

async function moverServicio(id, direccion) {
  await ejecutar(async () => {
    const resultado = await api(`/api/admin/servicios/${id}/orden`, { method: 'PATCH', datos: { direccion } })
    servicios.value = resultado.servicios
  })
}

function limpiarImagenTemporalServicio() {
  if (servicioImagenTemporal.value) URL.revokeObjectURL(servicioImagenTemporal.value)
  servicioImagenTemporal.value = null
}

onBeforeUnmount(() => {
  window.removeEventListener('hashchange', actualizarSeccion)
  limpiarImagenTemporal()
  limpiarImagenTemporalServicio()
  if (imagenNuevaTemporal.value) URL.revokeObjectURL(imagenNuevaTemporal.value)
})
</script>

<template>
  <div class="administracion">
    <header>
      <a class="marca" href="/">Zenli</a>
      <a v-if="usuario?.admin" href="/admin/agenda" class="acceso-agenda">Agenda</a>
      <a href="/" class="enlace-portada">Ver portada ↗</a>
      <button v-if="usuario" type="button" class="secundario" :disabled="ocupado" @click="salir">Cerrar sesión</button>
    </header>

    <LayoutAdministracion :usuario="usuario?.admin ? usuario : null" :seccion="seccionActiva.id">
    <main ref="contenidoPrincipal" tabindex="-1">
      <h1 id="titulo-seccion-admin">{{ usuario?.admin ? seccionActiva.nombre : 'Administración de Zenli' }}</h1>
      <p v-if="usuario" class="cuenta-actual">{{ usuario.email }}</p>
      <p v-if="cargando" role="status">Cargando…</p>
      <p v-if="error && (!usuario?.admin || seccionActiva.id !== 'servicios') && !accionProfesional" class="error" role="alert">{{ error }}</p>
      <p v-if="mensaje && (!usuario?.admin || seccionActiva.id !== 'servicios')" class="mensaje" role="status">{{ mensaje }}</p>

      <section v-if="!cargando && !usuario" class="panel inicio-sesion">
        <h2>Ingresá a tu cuenta</h2>
        <form @submit.prevent="ingresar">
          <label for="email">Correo</label>
          <input id="email" v-model="email" type="email" autocomplete="username" maxlength="180" required>
          <label for="clave">Contraseña</label>
          <input id="clave" v-model="clave" type="password" autocomplete="current-password" required>
          <button :disabled="ocupado || !csrf">Ingresar</button>
        </form>
      </section>

      <div v-if="usuario?.admin" class="paneles">
        <section v-show="seccionActiva.id === 'portada'" class="panel" aria-labelledby="titulo-seccion-admin">
          <p id="estado-portada">{{ soloImagen ? 'Estado público: solo imagen' : 'Estado público: portada completa' }}</p>
          <button type="button" class="secundario" aria-describedby="estado-portada ayuda-modo" :disabled="ocupado" @click="cambiarModoPortada">
            {{ soloImagen ? 'Subir sitio' : 'Bajar sitio' }}
          </button>
          <div><small id="ayuda-modo">Al bajar el sitio, se muestra la tarjeta de Zenli.</small></div>
          <form @submit.prevent="guardarPortada">
            <label for="telefono">Teléfono de WhatsApp</label>
            <input id="telefono" v-model="telefono" name="telefono" type="tel" autocomplete="tel" placeholder="+54…" :disabled="ocupado" required>
            <label for="direccion">Dirección (opcional)</label>
            <input id="direccion" v-model="direccion" name="direccion" type="text" maxlength="255" :disabled="ocupado">
            <label for="horarios">Horarios generales del centro (opcional)</label>
            <textarea id="horarios" v-model="horarios" name="horarios" rows="3" maxlength="2000" aria-describedby="ayuda-horarios" :disabled="ocupado"></textarea>
            <small id="ayuda-horarios">Son informativos y no configuran la disponibilidad de las profesionales. Dejá vacío para ocultar el dato.</small>
            <label for="instagram">Instagram (opcional)</label>
            <input id="instagram" v-model.trim="instagram" name="instagram" type="url" placeholder="https://www.instagram.com/…" maxlength="2048" aria-describedby="ayuda-redes" :disabled="ocupado">
            <label for="facebook">Facebook (opcional)</label>
            <input id="facebook" v-model.trim="facebook" name="facebook" type="url" placeholder="https://www.facebook.com/…" maxlength="2048" aria-describedby="ayuda-redes" :disabled="ocupado">
            <small id="ayuda-redes">Pegá el enlace completo del perfil. Dejá el campo vacío para ocultarlo.</small>
            <label for="imagen">Imagen principal</label>
            <img class="vista-previa" :src="vistaPrevia" alt="Vista previa de la portada">
            <input id="imagen" ref="entradaArchivo" name="imagen" type="file" accept="image/jpeg,image/png,image/webp" aria-describedby="ayuda-imagen" :disabled="ocupado" @change="elegirImagen">
            <small id="ayuda-imagen">JPG, PNG o WebP, hasta 4 MB de peso. La portada recorta la foto según el tamaño de pantalla.</small>
            <div class="acciones">
              <button :disabled="ocupado">Guardar portada</button>
              <button v-if="imagenGuardada || imagenTemporal" type="button" class="secundario" :disabled="ocupado || restaurar" @click="restaurarImagen">Usar imagen original</button>
            </div>
          </form>
        </section>

        <section v-show="seccionActiva.id === 'cuentas'" class="panel" aria-labelledby="titulo-seccion-admin">
          <ul class="lista">
            <li v-for="cuenta in usuarios" :key="cuenta.id">
              <div><strong>{{ cuenta.email }}</strong><small>{{ cuenta.activo ? 'Activa' : 'Desactivada' }}<template v-if="cuenta.profesional"> · {{ cuenta.profesional }}</template></small></div>
              <button type="button" class="secundario" :disabled="ocupado" @click="seleccionarUsuario(cuenta)">Editar</button>
            </li>
          </ul>
          <form v-if="usuarioEditado" :key="usuarioEditado.id" class="formulario-cuenta" @submit.prevent="guardarUsuario">
            <h3>{{ usuarioEditado.email }}</h3>
            <label v-if="usuarioEditado.id !== usuario.id" class="casilla"><input v-model="editarActivo" type="checkbox"> Cuenta activa</label>
            <label for="editar-clave">Nueva contraseña (opcional)</label>
            <input id="editar-clave" v-model="editarClave" type="password" autocomplete="new-password" minlength="12" maxlength="72">
            <div class="acciones">
              <button :disabled="ocupado">Guardar cuenta</button>
              <button type="button" class="secundario" :disabled="ocupado" @click="usuarioEditado = null; editarClave = ''">Cancelar</button>
            </div>
          </form>

          <form class="formulario-cuenta" @submit.prevent="crearUsuario">
            <h3>Nueva cuenta</h3>
            <label for="alta-email">Correo</label>
            <input id="alta-email" v-model="altaEmail" type="email" autocomplete="off" maxlength="180" required>
            <label for="alta-clave">Contraseña inicial</label>
            <input id="alta-clave" v-model="altaClave" type="password" autocomplete="new-password" minlength="12" maxlength="72" aria-describedby="ayuda-clave" required>
            <small id="ayuda-clave">Usá al menos 12 caracteres.</small>
            <label class="casilla"><input v-model="altaProfesional" type="checkbox"> También es profesional</label>
            <template v-if="altaProfesional">
              <label>Perfil profesional
                <select v-model="altaPerfil" required>
                  <option disabled value="">Elegí una opción</option>
                  <option value="nueva">Crear nueva profesional</option>
                  <option v-for="persona in perfilesSinCuenta" :key="persona.id" :value="persona.id">{{ persona.nombre }}</option>
                </select>
              </label>
              <label v-if="altaPerfil === 'nueva'">Nombre <input v-model.trim="altaNombre" maxlength="120" required></label>
            </template>
            <button :disabled="ocupado || (altaProfesional && !ajustesAgenda)">Crear cuenta</button>
          </form>
        </section>

        <section v-show="seccionActiva.id === 'configuracion-agenda'" id="configuracion-agenda" class="panel">
          <h2>Primeros pasos</h2>
          <ol class="pasos">
            <li><a href="#espacios">Configurá los espacios</a> donde se atiende.</li>
            <li><a href="#profesionales">Vinculá las cuentas de las profesionales</a> y <a href="#horarios-profesionales">cargá sus horarios</a>.</li>
            <li><a href="#servicios">Cargá los servicios</a> y <a href="#preparacion">definí su preparación</a>.</li>
            <li><a href="/admin/agenda">Abrí la agenda</a> y cargá el primer turno.</li>
          </ol>
        </section>

        <template v-if="ajustesAgenda">
          <section v-show="seccionActiva.id === 'espacios'" class="panel" aria-labelledby="titulo-seccion-admin">
            <ul class="lista">
              <li v-for="espacio in ajustesAgenda.espacios" :key="espacio.id">
                <div><strong>{{ espacio.nombre }}</strong><small v-if="espacio.detalle">{{ espacio.detalle }}</small></div>
                <button type="button" class="secundario" :disabled="ocupado" @click="editarEspacio(espacio)">Editar</button>
              </li>
            </ul>
            <form @submit.prevent="guardarEspacio">
              <h3>{{ espacioEditado ? 'Editar espacio' : 'Nuevo espacio' }}</h3>
              <label>Nombre <input v-model.trim="espacioNombre" maxlength="120" required></label>
              <label>Detalle (opcional) <textarea v-model.trim="espacioDetalle" maxlength="1000" rows="3"></textarea></label>
              <div class="acciones">
                <button :disabled="ocupado">{{ espacioEditado ? 'Guardar cambios' : 'Crear espacio' }}</button>
                <button v-if="espacioEditado" type="button" class="secundario" :disabled="ocupado" @click="editarEspacio()">Cancelar</button>
              </div>
            </form>
          </section>

          <section v-show="seccionActiva.id === 'profesionales'" class="panel">
            <div class="panel-header cabecera-profesionales">
              <div><h2>Profesionales</h2><small>Gestioná el equipo y sus cuentas de acceso.</small></div>
              <div class="acciones">
                <button type="button" class="secundario" :disabled="ocupado" @click="abrirDialogoProfesional('cuenta')">Crear o vincular cuenta</button>
                <button type="button" :disabled="ocupado" @click="abrirDialogoProfesional('perfil')">+ Nueva profesional</button>
              </div>
            </div>
            <ul class="lista">
              <li v-for="persona in ajustesAgenda.profesionales" :key="persona.id">
                <div><strong>{{ persona.nombre }}</strong><small>{{ Number(persona.admin) ? 'Profesional y administradora' : Number(persona.tiene_cuenta) ? 'Profesional' : 'Sin cuenta' }}</small></div>
                <button v-if="!Number(persona.tiene_cuenta)" type="button" class="secundario" :disabled="ocupado" @click="abrirDialogoProfesional('cuenta', persona.id)">Vincular cuenta</button>
              </li>
            </ul>
            <p v-if="!ajustesAgenda.profesionales.length" class="vacío">Todavía no hay profesionales cargadas.</p>
            <dialog ref="dialogoProfesional" class="modal-servicio" aria-labelledby="titulo-dialogo-profesional" @close="cerrarDialogoProfesional" @cancel="ocupado && $event.preventDefault()">
              <div class="modal-cabecera">
                <div><span class="modal-etiqueta">EQUIPO DE ZENLI</span><h2 id="titulo-dialogo-profesional">{{ accionProfesional === 'perfil' ? 'Nueva profesional' : 'Crear o vincular cuenta' }}</h2><p>{{ accionProfesional === 'perfil' ? 'Agregá una profesional sin crear una cuenta de acceso.' : 'Asigná una cuenta a una profesional del equipo.' }}</p></div>
                <button type="button" class="cerrar-modal" aria-label="Cerrar" :disabled="ocupado" @click="cerrarDialogoProfesional">✕</button>
              </div>
              <div class="modal-avisos"><p v-if="error" class="error" role="alert">{{ error }}</p></div>
            <form v-if="accionProfesional === 'perfil'" @submit.prevent="crearProfesional">
              <label>Nombre <input v-model.trim="nombreProfesional" maxlength="120" required></label>
              <button :disabled="ocupado">Crear profesional</button>
            </form>
            <form v-else-if="accionProfesional === 'cuenta'" @submit.prevent="crearCuentaProfesional">
              <label>Profesional
                <select v-model="cuenta.profesional" required>
                  <option disabled value="">Elegí una opción</option>
                  <option value="nueva">Crear nueva profesional</option>
                  <option v-for="persona in ajustesAgenda.profesionales.filter(item => !Number(item.tiene_cuenta))" :key="persona.id" :value="persona.id">{{ persona.nombre }}</option>
                </select>
              </label>
              <label v-if="cuenta.profesional === 'nueva'">Nombre <input v-model.trim="cuenta.nombre" maxlength="120" required></label>
              <label class="casilla"><input v-model="cuenta.cuenta_existente" type="checkbox"> Vincular una cuenta administradora existente</label>
              <label>Correo <input v-model="cuenta.email" type="email" maxlength="180" autocomplete="off" required></label>
              <template v-if="!cuenta.cuenta_existente">
                <label>Contraseña inicial <input v-model="cuenta.clave" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></label>
                <label class="casilla"><input v-model="cuenta.admin" type="checkbox"> También es administradora</label>
              </template>
              <button :disabled="ocupado">{{ cuenta.cuenta_existente ? 'Vincular cuenta' : 'Crear cuenta profesional' }}</button>
            </form>
              <div class="modal-pie"><button type="button" class="secundario" :disabled="ocupado" @click="cerrarDialogoProfesional">Cancelar</button></div>
            </dialog>
          </section>

          <section v-show="seccionActiva.id === 'horarios-profesionales'" id="horarios-profesionales" class="panel" aria-labelledby="titulo-horarios-profesionales">
            <h2 id="titulo-horarios-profesionales">Horarios de profesionales</h2>
            <label>Profesional
              <select v-model="profesionalHorario" :disabled="ocupado" @change="cargarHorario">
                <option value="">Elegí una profesional</option>
                <option v-for="persona in ajustesAgenda.profesionales" :key="persona.id" :value="persona.id">{{ persona.nombre }}</option>
              </select>
            </label>
            <form v-if="horarioProfesional" @submit.prevent="guardarHorario">
              <label class="casilla"><input v-model="horarioProfesional.por_franjas" type="checkbox" :disabled="ocupado"> Limitar turnos a las franjas cargadas</label>
              <small v-if="horarioProfesional.por_franjas && !horarioProfesional.franjas.length">Sin franjas, la profesional queda sin disponibilidad. Agregá una fecha cuando confirme que viene.</small>
              <small v-else-if="!horarioProfesional.por_franjas">Modo manual: los horarios se acuerdan al cargar cada turno. Se mantienen los controles de ocupación.</small>
              <div class="acciones">
                <button type="button" class="secundario" :disabled="ocupado" @click="agregarFranja('semanal')">+ Franja semanal</button>
                <button type="button" class="secundario" :disabled="ocupado" @click="agregarFranja('puntual')">+ Fecha puntual</button>
              </div>
              <fieldset v-for="(franja, indice) in horarioProfesional.franjas" :key="indice" :disabled="ocupado">
                <legend>{{ franja.tipo === 'semanal' ? 'Franja semanal' : 'Fecha puntual' }}</legend>
                <label v-if="franja.tipo === 'semanal'">Día
                  <select v-model.number="franja.dia_semana" required>
                    <option disabled value="">Elegí un día</option>
                    <option v-for="(dia, indiceDia) in diasSemana" :key="dia" :value="indiceDia + 1">{{ dia }}</option>
                  </select>
                </label>
                <label>{{ franja.tipo === 'semanal' ? 'Vigente desde' : 'Fecha' }}
                  <input v-model="franja.vigente_desde" type="date" required>
                </label>
                <label v-if="franja.tipo === 'semanal'">Vigente hasta (opcional)
                  <input v-model="franja.vigente_hasta" type="date" :min="franja.vigente_desde">
                </label>
                <div class="acciones">
                  <label>Desde (24 h) <EntradaHora v-model="franja.hora_inicio" segundos required /></label>
                  <label>Hasta (24 h) <EntradaHora v-model="franja.hora_fin" segundos permitir-medianoche required /></label>
                </div>
                <button type="button" class="secundario" @click="horarioProfesional.franjas.splice(indice, 1)">Quitar franja</button>
              </fieldset>
              <h3>Excepciones por fecha</h3>
              <small>El horario especial reemplaza las franjas de ese día. Un bloqueo impide agendar durante ese período, incluso en modo manual.</small>
              <div class="acciones">
                <button type="button" class="secundario" :disabled="ocupado" @click="agregarExcepcion('especial')">+ Horario especial</button>
                <button type="button" class="secundario" :disabled="ocupado" @click="agregarExcepcion('bloqueo')">+ Bloqueo</button>
              </div>
              <fieldset v-for="(excepcion, indice) in horarioProfesional.excepciones" :key="indice" :disabled="ocupado">
                <legend>{{ excepcion.tipo === 'especial' ? 'Horario especial' : 'Bloqueo' }}</legend>
                <label>Fecha <input v-model="excepcion.fecha" type="date" required></label>
                <label v-if="excepcion.tipo === 'bloqueo'" class="casilla"><input v-model="excepcion.todo_el_dia" type="checkbox"> Todo el día</label>
                <div v-if="!excepcion.todo_el_dia" class="acciones">
                  <label>Desde (24 h) <EntradaHora v-model="excepcion.hora_inicio" segundos required /></label>
                  <label>Hasta (24 h) <EntradaHora v-model="excepcion.hora_fin" segundos permitir-medianoche required /></label>
                </div>
                <button type="button" class="secundario" @click="horarioProfesional.excepciones.splice(indice, 1)">Quitar excepción</button>
              </fieldset>
              <button :disabled="ocupado">Guardar horarios</button>
            </form>
          </section>

          <section v-show="seccionActiva.id === 'preparacion'" class="panel" aria-labelledby="titulo-seccion-admin">
            <form @submit.prevent="guardarPreparacionAgenda">
              <fieldset>
                <legend>¿Todos los servicios tienen el mismo tiempo de preparación?</legend>
                <label class="casilla"><input v-model="modoPreparacion" type="radio" value="general" required> Sí, el mismo para todos</label>
                <label class="casilla"><input v-model="modoPreparacion" type="radio" value="por_servicio" required> No, se define por servicio</label>
              </fieldset>
              <label v-if="modoPreparacion === 'general'">Minutos de preparación <input v-model.number="minutosPreparacion" type="number" min="0" max="1440" required></label>
              <template v-if="modoPreparacion === 'por_servicio'">
                <p>Dejá sin completar los servicios que todavía no querés agendar.</p>
                <label v-for="item in servicios" :key="item.id">
                  {{ item.nombre }} (min) <input v-model.number="preparacionServicios[item.id]" type="number" min="0" max="1440">
                </label>
              </template>
              <button :disabled="ocupado || !modoPreparacion">Guardar preparación</button>
            </form>
          </section>
        </template>

        <!-- Servicios de la portada -->
        <section v-show="seccionActiva.id === 'servicios'" class="panel" aria-labelledby="titulo-servicios">
          <div class="avisos-servicios">
            <p v-if="error" class="error" role="alert">{{ error }}</p>
            <p v-if="mensaje" class="mensaje" role="status">{{ mensaje }}</p>
          </div>
          <div class="panel-header">
            <h2 id="titulo-servicios">Servicios de la portada</h2>
            <button v-if="!servicioCreando" type="button" class="secundario" :disabled="ocupado" @click="iniciarCreacion">+ Nuevo servicio</button>
          </div>

          <ul class="lista-servicios">
            <li v-for="(servicio, index) in servicios" :key="servicio.id" :class="{ oculto: !servicio.visible }">
              <div class="servicio-info">
                <span class="servicio-orden">{{ index + 1 }}</span>
                <div class="servicio-detalle">
                  <strong>{{ servicio.nombre }}</strong>
                  <small>{{ servicio.descripcion_corta }}</small>
                  <small v-if="servicio.duracion_minima == null || servicio.profesionales_requeridos == null">Configuración de agenda incompleta</small>
                  <small v-else>Mínimo: {{ servicio.duracion_minima }} min · Profesionales: {{ servicio.profesionales_requeridos }}</small>
                  <span v-if="servicio.imagen_version" class="badge">con imagen</span>
                  <span v-else class="badge sin-imagen">sin imagen</span>
                </div>
              </div>
              <div class="servicio-acciones">
                <button type="button" class="secundario icono" :disabled="ocupado || index === 0" title="Mover arriba" @click="moverServicio(servicio.id, -1)">↑</button>
                <button type="button" class="secundario icono" :disabled="ocupado || index === servicios.length - 1" title="Mover abajo" @click="moverServicio(servicio.id, 1)">↓</button>
                <button type="button" class="secundario" :disabled="ocupado" @click="cambiarVisibilidad(servicio.id, !servicio.visible)">
                  {{ servicio.visible ? 'Ocultar' : 'Mostrar' }}
                </button>
                <button type="button" class="secundario" :disabled="ocupado" @click="iniciarEdicion(servicio)">Editar</button>
                <button type="button" class="peligro" :disabled="ocupado" @click="eliminarServicio(servicio.id)">Eliminar</button>
              </div>
            </li>
          </ul>
          <p v-if="servicios.length === 0" class="vacío">No hay servicios. Creá el primero.</p>

          <!-- Formulario crear -->
          <form v-if="servicioCreando" class="formulario-servicio" @submit.prevent="crearServicio">
            <p v-if="crearError" class="error inline" role="alert">{{ crearError }}</p>
            <h3>Nuevo servicio</h3>
            <label for="nuevo-nombre">Nombre</label>
            <input id="nuevo-nombre" v-model="nuevoNombre" type="text" maxlength="100" placeholder="Ej: Descontracturante" :disabled="ocupado" required>
            <label for="nuevo-desc">Descripción corta</label>
            <input id="nuevo-desc" v-model="nuevoDescCorta" type="text" maxlength="255" placeholder="Ej: Presión intensa y técnicas de masaje tailandés." :disabled="ocupado" required>
            <label for="nuevo-detalle">Detalle extenso <small>(opcional)</small></label>
            <textarea id="nuevo-detalle" v-model="nuevoDetalle" rows="4" placeholder="Descripción completa del masaje, beneficios, técnica…" :disabled="ocupado"></textarea>
            <small>Después de crear el servicio, configurá su duración mínima y profesionales para poder agendarlo.</small>
            <label for="nuevo-imagen">Imagen</label>
            <div v-if="imagenNuevaTemporal" class="vista-previa-servicio">
              <img :src="imagenNuevaTemporal" alt="Vista previa">
            </div>
            <input id="nuevo-imagen" type="file" accept="image/jpeg,image/png,image/webp" :disabled="ocupado" @change="elegirImagenServicio">
            <small>JPG, PNG o WebP, hasta 4 MB de peso. Se mostrará en la tarjeta de la portada.</small>
            <div class="acciones">
              <button :disabled="ocupado">Crear servicio</button>
              <button type="button" class="secundario" :disabled="ocupado" @click="cancelarCreacion">Cancelar</button>
            </div>
          </form>

          <dialog ref="dialogoServicio" class="modal-servicio" aria-labelledby="titulo-editar-servicio" @close="cancelarEdicion" @cancel="ocupado && $event.preventDefault()">
            <template v-if="servicioEditando">
            <div class="modal-cabecera">
              <div><span class="modal-etiqueta">SERVICIOS DE ZENLI</span><h2 id="titulo-editar-servicio">Editar servicio</h2><p>{{ servicioEditando.nombre }}</p></div>
              <button type="button" class="cerrar-modal" aria-label="Cerrar edición" :disabled="ocupado" @click="cancelarEdicion">✕</button>
            </div>
            <div class="modal-avisos" aria-live="polite">
              <p v-if="editarError" class="error inline" role="alert">{{ editarError }}</p>
              <p v-if="editarMensaje" class="mensaje" role="status">{{ editarMensaje }}</p>
            </div>
          <form class="formulario-servicio" :key="servicioEditando.id" @submit.prevent="guardarEdicion">
            <h3>Información para la portada</h3>
            <label for="edit-nombre">Nombre</label>
            <input id="edit-nombre" v-model="editNombre" type="text" maxlength="100" :disabled="ocupado" required>
            <label for="edit-desc">Descripción corta</label>
            <input id="edit-desc" v-model="editDescCorta" type="text" maxlength="255" :disabled="ocupado" required>
            <label for="edit-detalle">Detalle extenso <small>(opcional)</small></label>
            <textarea id="edit-detalle" v-model="editDetalle" rows="4" :disabled="ocupado"></textarea>
            <label for="edit-imagen">Imagen</label>
            <div v-if="servicioEditando.imagen_version" class="imagen-actual">
              <img :src="`/api/servicios/${servicioEditando.id}/imagen/${servicioEditando.imagen_version}`" alt="Imagen actual">
            </div>
            <div v-if="servicioImagenTemporal" class="vista-previa-servicio">
              <img :src="servicioImagenTemporal" alt="Vista previa nueva">
            </div>
            <input id="edit-imagen" ref="entradaImagenEdicion" type="file" accept="image/jpeg,image/png,image/webp" :disabled="ocupado" @change="elegirImagenEdicion">
            <label v-if="servicioEditando.imagen_version" class="casilla">
              <input v-model="editEliminarImagen" type="checkbox" :disabled="ocupado"> Eliminar imagen actual
            </label>
            <small>Subí una nueva imagen para reemplazar la actual o marcá para eliminarla.</small>
            <div class="acciones">
              <button :disabled="ocupado">Guardar cambios</button>
            </div>
          </form>
          <form class="formulario-servicio configuracion-modal" @submit.prevent="guardarConfiguracionServicio">
            <h3>Configuración de agenda</h3>
            <label>Tiempo mínimo (minutos) <input v-model.number="editMinimo" type="number" min="1" :disabled="ocupado"></label>
            <label>Cantidad mínima de profesionales <input v-model.number="editProfesionales" type="number" min="1" :disabled="ocupado"></label>
            <small>Sin estos datos el servicio no puede agendarse. La duración mínima es informativa; se pueden elegir las cuatro duraciones.</small>
            <button :disabled="ocupado">Guardar configuración de agenda</button>
          </form>
          <div class="modal-pie"><button type="button" class="secundario" :disabled="ocupado" @click="cancelarEdicion">Cerrar</button></div>
            </template>
          </dialog>
        </section>
      </div>
    </main>
    </LayoutAdministracion>
  </div>
</template>

<style scoped>
.administracion { min-height: 100svh; background: #f8f3e7; color: #15372a; }
header { background: #08271c; color: #fff7e6; display: flex; align-items: center; gap: 1rem; padding: .9rem max(1rem, calc((100% - 70rem) / 2)); }
header a { color: inherit; text-underline-offset: .2em; }
.marca { color: #eac26f; font: 2rem Georgia, serif; text-decoration: none; margin-right: auto; }
header .acceso-agenda { padding: .45rem .75rem; border-radius: .4rem; background: #eac26f; color: #08271c; font-size: .88rem; font-weight: 750; text-decoration: none; }
header .acceso-agenda:hover { background: #f3d28b; }
.enlace-portada { font-size: .88rem; }
main { padding-block: clamp(1.25rem, 4vw, 2.6rem); }
main:focus { outline: none; }
.pasos { padding-left: 1.2rem; }
.pasos li { margin-block: .5rem; }
.pasos a { color: #214d3a; text-underline-offset: .2em; }
h1, h2 { font-family: Georgia, serif; }
h1 { margin: 0 0 1.25rem; font-size: clamp(2rem, 5vw, 3rem); }
h2, h3 { margin: 0 0 .4rem; }
h3 { font-size: 1rem; overflow-wrap: anywhere; }
.cuenta-actual { margin-top: -.85rem; color: #526255; overflow-wrap: anywhere; }
fieldset { min-width: 0; border: 1px solid #e4dac3; border-radius: .4rem; }
.paneles { max-width: 58rem; }
.panel { background: #fffaf1; padding: clamp(1rem, 3vw, 1.6rem); border: 1px solid #e4dac3; border-radius: .9rem; }
.inicio-sesion { max-width: 28rem; }
form { display: grid; gap: .55rem; margin-top: 1rem; }
label { font-weight: 650; font-size: .88rem; }
input, button { font: inherit; }
input:not([type='checkbox'], [type='radio']), select, textarea { min-width: 0; width: 100%; padding: .62rem .7rem; border: 1px solid #aaab98; border-radius: .4rem; background: #fffdf7; color: #15372a; }
input + label { margin-top: .45rem; }
button { width: fit-content; padding: .56rem .85rem; border: 1px solid #214d3a; border-radius: .4rem; background: #214d3a; color: #fffdfa; cursor: pointer; font-weight: 650; font-size: .89rem; }
form > button, .acciones { margin-top: .5rem; }
button.secundario { background: #fff9ec; color: #214d3a; }
button:disabled { opacity: .54; cursor: default; }
button:focus-visible, input:focus-visible, a:focus-visible { outline: 2px solid #8f631e; outline-offset: 3px; }
.vista-previa { width: 100%; aspect-ratio: 2; object-fit: cover; border-radius: .5rem; background: #08271c; }
.acciones { display: flex; flex-wrap: wrap; gap: .5rem; }
small { font-size: .79rem; color: #526255; }
.lista { margin: .75rem 0 0; padding: 0; list-style: none; }
.lista li { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .6rem 0; border-bottom: 1px solid #e4dac3; font-size: .88rem; }
.lista li div { min-width: 0; overflow-wrap: anywhere; }
.lista small { display: block; }
.casilla { display: flex; align-items: center; gap: .4rem; }
.formulario-cuenta { margin-top: 1.25rem; }
.error, .mensaje { border-radius: .4rem; padding: .65rem .8rem; }
.error { background: #f9e3df; color: #75271f; }
.error.inline { margin: 0 0 .5rem; }
.mensaje { background: #e4efdf; color: #194832; }

.panel-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.lista-servicios { margin: .75rem 0 0; padding: 0; list-style: none; }
.lista-servicios li { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .6rem 0; border-bottom: 1px solid #e4dac3; font-size: .88rem; }
.lista-servicios li.oculto { opacity: .5; }
.servicio-info { display: flex; align-items: center; gap: .6rem; min-width: 0; }
.servicio-orden { width: 1.6rem; height: 1.6rem; display: flex; align-items: center; justify-content: center; background: #214d3a; color: #fffdfa; border-radius: 50%; font-size: .75rem; font-weight: 700; flex-shrink: 0; }
.servicio-detalle { min-width: 0; }
.servicio-detalle strong { display: block; }
.servicio-detalle small { display: block; color: #526255; }
.servicio-acciones { display: flex; align-items: center; gap: .35rem; flex-shrink: 0; }
.servicio-acciones button.icono { padding: .3rem .45rem; font-size: .9rem; }
.servicio-acciones button.peligro { background: #75271f; border-color: #75271f; }
.badge { display: inline-block; font-size: .7rem; padding: .1rem .4rem; border-radius: .3rem; background: #e4efdf; color: #194832; margin-left: .3rem; }
.badge.sin-imagen { background: #f9e3df; color: #75271f; }
.formulario-servicio { margin-top: 1.25rem; }
.formulario-servicio textarea { min-width: 0; width: 100%; padding: .62rem .7rem; border: 1px solid #aaab98; border-radius: .4rem; background: #fffdf7; color: #15372a; font: inherit; resize: vertical; }
.avisos-servicios { display: contents; }
.avisos-servicios p { position: sticky; top: .5rem; z-index: 1; margin: 0 0 .75rem; box-shadow: 0 .25rem 1rem #15372a1a; }
.modal-servicio { width: min(42rem, calc(100% - 2rem)); max-height: min(90svh, 60rem); padding: clamp(1.2rem, 4vw, 2rem); border: 1px solid #e4dac3; border-radius: 1.1rem; background: #fffaf1; color: #15372a; box-shadow: 0 1.5rem 5rem #08271c55; }
.modal-servicio::backdrop { background: #08271cb8; }
.modal-cabecera { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; padding-bottom: 1rem; border-bottom: 1px solid #e4dac3; }
.modal-cabecera h2 { font-size: clamp(1.6rem, 4vw, 2.1rem); margin: .25rem 0; }
.modal-cabecera p { margin: 0; color: #526255; overflow-wrap: anywhere; }
.modal-etiqueta { color: #8f631e; font-size: .7rem; font-weight: 750; letter-spacing: .12em; }
button.cerrar-modal { flex-shrink: 0; border: 1px solid #e4dac3; border-radius: 50%; width: 2.5rem; height: 2.5rem; padding: 0; background: #fffdf7; color: #214d3a; }
.modal-avisos { display: contents; }
.modal-avisos p { position: sticky; top: 0; z-index: 1; margin: .6rem 0; box-shadow: 0 .25rem 1rem #15372a1a; }
.modal-servicio .formulario-servicio { margin-top: 1.4rem; }
.configuracion-modal { padding-top: 1.4rem; border-top: 1px solid #e4dac3; }
.configuracion-modal label { display: grid; gap: .45rem; }
.modal-pie { display: flex; justify-content: flex-end; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #e4dac3; }
.vista-previa-servicio, .imagen-actual { margin: .5rem 0; }
.vista-previa-servicio img, .imagen-actual img { max-width: min(100%, 20rem); height: auto; border-radius: .5rem; border: 1px solid #e4dac3; }
.vacío { color: #526255; font-style: italic; margin: 1rem 0 0; }

@media (max-width: 46rem) {
  .cabecera-profesionales { align-items: flex-start; flex-direction: column; }
  header { flex-wrap: wrap; gap: .55rem; }
  header .acceso-agenda { padding-inline: .55rem; }
  header button { padding-inline: .45rem; }
  .lista-servicios li { flex-direction: column; align-items: flex-start; }
  .servicio-acciones { width: 100%; justify-content: flex-start; }
}
</style>
