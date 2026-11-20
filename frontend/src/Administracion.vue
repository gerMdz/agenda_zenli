<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import portadaOriginal from './image/portada.webp'

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
const editArchivo = ref(null)
const editEliminarImagen = ref(false)
const servicioImagenTemporal = ref(null)
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
    limpiarImagenTemporal()
  }
}

async function cargarSesion() {
  aplicarSesion(await api('/api/admin/sesion'))
}

async function cargarPanel() {
  const [portada, listado, svcs] = await Promise.all([
    api('/api/portada'),
    api('/api/admin/usuarios'),
    api('/api/admin/servicios'),
  ])
  aplicarPortada(portada)
  usuarios.value = listado.usuarios
  servicios.value = svcs.servicios
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
  await ejecutar(async () => {
    await cargarSesion()
    if (usuario.value) await cargarPanel()
  })
  cargando.value = false
})

onBeforeUnmount(limpiarImagenTemporal)

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

function crearUsuario() {
  return ejecutar(async () => {
    const listado = await api('/api/admin/usuarios', {
      method: 'POST',
      datos: { email: altaEmail.value, clave: altaClave.value },
    })
    usuarios.value = listado.usuarios
    altaEmail.value = ''
    altaClave.value = ''
    mensaje.value = 'Cuenta administradora creada.'
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
  servicioImagenTemporal.value = null
  crearError.value = ''
  error.value = ''
}

function cancelarCreacion() {
  servicioCreando.value = false
  nuevoNombre.value = ''
  nuevoDescCorta.value = ''
  nuevoDetalle.value = ''
  nuevoArchivo.value = null
  if (servicioImagenTemporal.value) URL.revokeObjectURL(servicioImagenTemporal.value)
  servicioImagenTemporal.value = null
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
  if (servicioImagenTemporal.value) URL.revokeObjectURL(servicioImagenTemporal.value)
  servicioImagenTemporal.value = URL.createObjectURL(archivo)
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

function iniciarEdicion(servicio) {
  servicioEditando.value = servicio
  editNombre.value = servicio.nombre
  editDescCorta.value = servicio.descripcion_corta
  editDetalle.value = servicio.detalle
  editArchivo.value = null
  editEliminarImagen.value = false
  if (servicioImagenTemporal.value) URL.revokeObjectURL(servicioImagenTemporal.value)
  servicioImagenTemporal.value = null
  editarError.value = ''
  error.value = ''
}

function cancelarEdicion() {
  servicioEditando.value = null
  editNombre.value = ''
  editDescCorta.value = ''
  editDetalle.value = ''
  editArchivo.value = null
  editEliminarImagen.value = false
  if (servicioImagenTemporal.value) URL.revokeObjectURL(servicioImagenTemporal.value)
  servicioImagenTemporal.value = null
  editarError.value = ''
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
  if (ocupado.value) return
  ocupado.value = true
  try {
    const datos = new FormData()
    datos.set('nombre', editNombre.value)
    datos.set('descripcion_corta', editDescCorta.value)
    datos.set('detalle', editDetalle.value)
    if (editArchivo.value) datos.set('imagen', editArchivo.value)
    if (editEliminarImagen.value) datos.set('eliminar_imagen', '1')
    const resultado = await api(`/api/admin/servicios/${servicioEditando.value.id}`, { method: 'PATCH', datos })
    servicios.value = resultado.servicios
    cancelarEdicion()
    mensaje.value = 'Servicio actualizado.'
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
  limpiarImagenTemporal()
  limpiarImagenTemporalServicio()
})
</script>

<template>
  <div class="administracion">
    <header>
      <a class="marca" href="/">Zenli</a>
      <a v-if="usuario" href="/admin/agenda" class="enlace-portada">Agenda</a>
      <a href="/" class="enlace-portada">Ver portada ↗</a>
      <button v-if="usuario" type="button" class="secundario" :disabled="ocupado" @click="salir">Cerrar sesión</button>
    </header>

    <main>
      <h1>Administración de Zenli</h1>
      <p v-if="usuario" class="cuenta-actual">{{ usuario.email }}</p>
      <p v-if="cargando" role="status">Cargando…</p>
      <p v-if="error" class="error" role="alert">{{ error }}</p>
      <p v-if="mensaje" class="mensaje" role="status">{{ mensaje }}</p>

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

      <div v-if="usuario" class="paneles">
        <section class="panel" aria-labelledby="titulo-portada">
          <h2 id="titulo-portada">Portada</h2>
          <p id="estado-portada">{{ soloImagen ? 'Estado público: solo imagen' : 'Estado público: portada completa' }}</p>
          <button type="button" class="secundario" aria-describedby="estado-portada ayuda-modo" :disabled="ocupado" @click="cambiarModoPortada">
            {{ soloImagen ? 'Subir sitio' : 'Bajar sitio' }}
          </button>
          <div><small id="ayuda-modo">Al bajar el sitio, queda visible la imagen principal actual.</small></div>
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

        <section class="panel" aria-labelledby="titulo-usuarios">
          <h2 id="titulo-usuarios">Cuentas administradoras</h2>
          <ul class="lista">
            <li v-for="cuenta in usuarios" :key="cuenta.id">
              <div><strong>{{ cuenta.email }}</strong><small>{{ cuenta.activo ? 'Activa' : 'Desactivada' }}</small></div>
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
            <button :disabled="ocupado">Crear cuenta</button>
          </form>
        </section>

        <!-- Servicios de la portada -->
        <section class="panel" aria-labelledby="titulo-servicios" style="grid-column: 1 / -1;">
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
            <label for="nuevo-imagen">Imagen</label>
            <div v-if="servicioImagenTemporal" class="vista-previa-servicio">
              <img :src="servicioImagenTemporal" alt="Vista previa">
            </div>
            <input id="nuevo-imagen" type="file" accept="image/jpeg,image/png,image/webp" :disabled="ocupado" @change="elegirImagenServicio">
            <small>JPG, PNG o WebP, hasta 4 MB de peso. Se mostrará en la tarjeta de la portada.</small>
            <div class="acciones">
              <button :disabled="ocupado">Crear servicio</button>
              <button type="button" class="secundario" :disabled="ocupado" @click="cancelarCreacion">Cancelar</button>
            </div>
          </form>

          <!-- Formulario editar -->
          <form v-if="servicioEditando" class="formulario-servicio" :key="servicioEditando.id" @submit.prevent="guardarEdicion">
            <p v-if="editarError" class="error inline" role="alert">{{ editarError }}</p>
            <h3>Editar: {{ servicioEditando.nombre }}</h3>
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
            <input id="edit-imagen" type="file" accept="image/jpeg,image/png,image/webp" :disabled="ocupado" @change="elegirImagenEdicion">
            <label v-if="servicioEditando.imagen_version" class="casilla">
              <input v-model="editEliminarImagen" type="checkbox" :disabled="ocupado"> Eliminar imagen actual
            </label>
            <small>Subí una nueva imagen para reemplazar la actual o marcá para eliminarla.</small>
            <div class="acciones">
              <button :disabled="ocupado">Guardar cambios</button>
              <button type="button" class="secundario" :disabled="ocupado" @click="cancelarEdicion">Cancelar</button>
            </div>
          </form>
        </section>
      </div>
    </main>
  </div>
</template>

<style scoped>
.administracion { min-height: 100svh; background: #f8f3e7; color: #15372a; }
header { background: #08271c; color: #fff7e6; display: flex; align-items: center; gap: 1rem; padding: .9rem max(1rem, calc((100% - 70rem) / 2)); }
header a { color: inherit; text-underline-offset: .2em; }
.marca { color: #eac26f; font: 2rem Georgia, serif; text-decoration: none; margin-right: auto; }
.enlace-portada { font-size: .88rem; }
main { width: min(calc(100% - 2rem), 70rem); margin-inline: auto; padding-block: clamp(1.5rem, 5vw, 3.25rem); }
h1, h2 { font-family: Georgia, serif; }
h1 { margin: 0 0 1.25rem; font-size: clamp(2rem, 5vw, 3rem); }
h2, h3 { margin: 0 0 .4rem; }
h3 { font-size: 1rem; overflow-wrap: anywhere; }
.cuenta-actual { margin-top: -.85rem; color: #526255; overflow-wrap: anywhere; }
.paneles { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; align-items: start; }
.panel { background: #fffaf1; padding: clamp(1rem, 3vw, 1.6rem); border: 1px solid #e4dac3; border-radius: .9rem; }
.inicio-sesion { max-width: 28rem; }
form { display: grid; gap: .55rem; margin-top: 1rem; }
label { font-weight: 650; font-size: .88rem; }
input, button { font: inherit; }
input:not([type='checkbox']) { min-width: 0; width: 100%; padding: .62rem .7rem; border: 1px solid #aaab98; border-radius: .4rem; background: #fffdf7; color: #15372a; }
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
.vista-previa-servicio, .imagen-actual { margin: .5rem 0; }
.vista-previa-servicio img, .imagen-actual img { max-width: 20rem; height: auto; border-radius: .5rem; border: 1px solid #e4dac3; }
.vacío { color: #526255; font-style: italic; margin: 1rem 0 0; }

@media (max-width: 46rem) {
  .paneles { grid-template-columns: 1fr; }
  .lista-servicios li { flex-direction: column; align-items: flex-start; }
  .servicio-acciones { width: 100%; justify-content: flex-start; }
}
</style>
