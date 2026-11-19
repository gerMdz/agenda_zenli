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
const instagram = ref('')
const facebook = ref('')
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
      413: 'La imagen supera el tamaño permitido.',
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
  const [portada, listado] = await Promise.all([api('/api/portada'), api('/api/admin/usuarios')])
  aplicarPortada(portada)
  usuarios.value = listado.usuarios
}

function aplicarPortada(portada) {
  telefono.value = portada.telefono
  instagram.value = portada.instagram ?? ''
  facebook.value = portada.facebook ?? ''
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
</script>

<template>
  <div class="administracion">
    <header>
      <a class="marca" href="/">Zenli</a>
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
          <form @submit.prevent="guardarPortada">
            <label for="telefono">Teléfono de WhatsApp</label>
            <input id="telefono" v-model="telefono" name="telefono" type="tel" autocomplete="tel" placeholder="+54…" :disabled="ocupado" required>
            <label for="instagram">Instagram (opcional)</label>
            <input id="instagram" v-model.trim="instagram" name="instagram" type="url" placeholder="https://www.instagram.com/…" maxlength="2048" aria-describedby="ayuda-redes" :disabled="ocupado">
            <label for="facebook">Facebook (opcional)</label>
            <input id="facebook" v-model.trim="facebook" name="facebook" type="url" placeholder="https://www.facebook.com/…" maxlength="2048" aria-describedby="ayuda-redes" :disabled="ocupado">
            <small id="ayuda-redes">Pegá el enlace completo del perfil. Dejá el campo vacío para ocultarlo.</small>
            <label for="imagen">Imagen principal</label>
            <img class="vista-previa" :src="vistaPrevia" alt="Vista previa de la portada">
            <input id="imagen" ref="entradaArchivo" name="imagen" type="file" accept="image/jpeg,image/png,image/webp" aria-describedby="ayuda-imagen" :disabled="ocupado" @change="elegirImagen">
            <small id="ayuda-imagen">JPG, PNG o WebP, hasta 4 MB. La portada recorta la foto según el tamaño de pantalla.</small>
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
.mensaje { background: #e4efdf; color: #194832; }

@media (max-width: 46rem) {
  .paneles { grid-template-columns: 1fr; }
}
</style>
