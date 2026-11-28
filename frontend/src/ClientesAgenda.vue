<script setup>
import { ref, watch } from 'vue'
import FichaClienteDialog from './FichaClienteDialog.vue'
import { enlaceWhatsApp } from './formatoWhatsApp.js'

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
const dialogoConfirmarWA = ref(null)
const confirmacionWA = ref(null)
const texto = ref('')
const estados = { programado: 'Programado', atendido: 'Atendido', ausente: 'Ausente' }
const fechaHora = new Intl.DateTimeFormat('es-AR', {
  dateStyle: 'short', timeStyle: 'short', hourCycle: 'h23', timeZone: 'America/Argentina/Mendoza',
})
const fechaVisible = valor => fechaHora.format(new Date(valor))

function accionarWhatsAppCliente(cliente, mensaje, evento) {
  if (cliente.no_contactar) {
    if (evento) evento.preventDefault()
    confirmacionWA.value = {
      nombre: cliente.nombre,
      telefono: cliente.telefono,
      url: enlaceWhatsApp(cliente.telefono, mensaje),
    }
    dialogoConfirmarWA.value?.showModal()
  }
}

function abrirWhatsAppConfirmado() {
  if (!confirmacionWA.value?.url) return
  const url = confirmacionWA.value.url
  dialogoConfirmarWA.value?.close()
  confirmacionWA.value = null
  window.open(url, '_blank', 'noopener,noreferrer')
}

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
          <div>
            <strong>{{ cliente.nombre }}</strong>
            <span v-if="cliente.no_contactar" class="badge-no-contactar">🚫 No contactar</span>
            <small v-if="cliente.telefono">
              {{ cliente.telefono }}
              <a v-if="enlaceWhatsApp(cliente.telefono)"
                 :href="cliente.no_contactar ? '#' : enlaceWhatsApp(cliente.telefono, `Hola ${cliente.nombre}, te escribimos desde Zenli Masoterapia.`)"
                 :target="cliente.no_contactar ? undefined : '_blank'"
                 rel="noopener noreferrer"
                 class="enlace-wa-linea"
                 :class="{ 'enlace-wa-linea--precaucion': cliente.no_contactar }"
                 :title="cliente.no_contactar ? 'Precaución: El cliente solicitó NO ser contactado' : 'Escribir por WhatsApp'"
                 @click="accionarWhatsAppCliente(cliente, `Hola ${cliente.nombre}, te escribimos desde Zenli Masoterapia.`, $event)">
                · WhatsApp <span v-if="cliente.no_contactar" class="alerta-wa-no-contactar">⚠</span>
              </a>
              <!-- Para habilitar llamadas directas en el futuro, descomentar la siguiente línea:
              <a :href="'tel:' + cliente.telefono" class="enlace-telefono-linea"> · Llamar</a>
              -->
            </small>
            <small v-if="cliente.email">{{ cliente.email }}</small>
            <small v-if="cliente.archivado_en">Archivada</small>
          </div>
          <button type="button" class="secundario" :disabled="ocupado" @click="emit('seleccionar', cliente.id)">Ver ficha</button>
        </li>
      </ul>
      <p v-if="listado.hay_mas">Ingresá un nombre más completo para acotar la búsqueda.</p>
    </template>

    <template v-if="ficha">
      <div class="barra">
        <p v-if="ficha.cliente.archivado_en">Ficha archivada</p>
        <span v-if="ficha.cliente.no_contactar" class="aviso-alerta-contacto">
          🚫 <strong>SOLICITÓ NO SER CONTACTADO:</strong> No enviar WhatsApp ni llamadas salvo urgencia extrema.
        </span>
        <div v-if="ficha.editable" class="acciones">
          <button v-if="!ficha.cliente.archivado_en" type="button" class="secundario" :disabled="ocupado" @click="editar">Editar ficha</button>
          <button type="button" class="secundario" :disabled="ocupado" @click="error = ''; dialogoArchivo.showModal()">{{ ficha.cliente.archivado_en ? 'Reactivar ficha' : 'Archivar ficha' }}</button>
        </div>
      </div>
      <dl v-if="ficha.cliente.telefono || ficha.cliente.email" class="contacto">
        <template v-if="ficha.cliente.telefono">
          <dt>Teléfono</dt>
          <dd class="dato-telefono">
            <span>{{ ficha.cliente.telefono }}</span>
            <a v-if="enlaceWhatsApp(ficha.cliente.telefono)"
               :href="ficha.cliente.no_contactar ? '#' : enlaceWhatsApp(ficha.cliente.telefono, `Hola ${ficha.cliente.nombre}, te escribimos desde Zenli Masoterapia.`)"
               :target="ficha.cliente.no_contactar ? undefined : '_blank'"
               rel="noopener noreferrer"
               class="boton-wa"
               :class="{ 'boton-wa--precaucion': ficha.cliente.no_contactar }"
               :title="ficha.cliente.no_contactar ? 'Precaución: El cliente solicitó NO ser contactado' : 'Escribir por WhatsApp'"
               @click="accionarWhatsAppCliente(ficha.cliente, `Hola ${ficha.cliente.nombre}, te escribimos desde Zenli Masoterapia.`, $event)">
              <span class="icono-wa" aria-hidden="true">💬</span> WhatsApp
              <span v-if="ficha.cliente.no_contactar" class="alerta-wa-no-contactar">⚠</span>
            </a>
            <!-- Para habilitar llamadas directas en el futuro, descomentar la siguiente línea:
            <a :href="'tel:' + ficha.cliente.telefono" class="boton-telefono" title="Llamar al cliente">Llamar</a>
            -->
            <span v-if="ficha.cliente.no_contactar" class="etiqueta-bloqueo-contacto">
              (Preferencia: no contactar)
            </span>
          </dd>
        </template>
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
    <dialog ref="dialogoConfirmarWA" class="modal-confirmar-wa" aria-labelledby="titulo-confirmar-wa-cliente" @close="confirmacionWA = null" @cancel="confirmacionWA = null">
      <div class="caja-confirmar-wa">
        <div class="icono-alerta-wa" aria-hidden="true">🚫</div>
        <h3 id="titulo-confirmar-wa-cliente">Atención: Cliente no contactable</h3>
        <p class="advertencia-wa">
          El cliente <strong>{{ confirmacionWA?.nombre }}</strong> solicitó explícitamente <em>NO ser contactado</em> por ningún medio.
        </p>
        <p class="detalle-advertencia-wa">
          Para evitar enviar mensajes por error, se requiere confirmación consciente. ¿Realmente necesitás abrir WhatsApp para este cliente?
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
.dato-telefono { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
.boton-wa { display: inline-flex; align-items: center; gap: .35rem; padding: .25rem .55rem; background: #25d366; color: #072a15; font-size: .82rem; font-weight: 600; border-radius: .35rem; text-decoration: none; border: 1px solid #1ebe5d; line-height: 1.2; }
.boton-wa:hover { background: #20ba59; color: #000; }
.boton-wa--precaucion { background: #fce8e6; border-color: #f1b3ad; color: #8a2a20; }
.boton-wa--precaucion:hover { background: #f8d5d1; color: #641911; }
.icono-wa { font-size: .95rem; }
.enlace-wa-linea { color: #167a3f; font-weight: 600; text-decoration: none; margin-left: .35rem; }
.enlace-wa-linea:hover { text-decoration: underline; }
.enlace-wa-linea--precaucion { color: #b42b1f; font-weight: 700; }
.alerta-wa-no-contactar { font-weight: 800; margin-left: .15rem; }
.boton-telefono, .enlace-telefono-linea { color: #214d3a; font-weight: 600; text-decoration: none; }
.badge-no-contactar { display: inline-block; padding: .15rem .45rem; background: #fae2dc; color: #8e1c13; font-size: .75rem; font-weight: 700; border-radius: .3rem; margin-left: .45rem; vertical-align: middle; }
.aviso-alerta-contacto { display: block; padding: .6rem .85rem; background: #fdf0ed; border: 1px solid #f4bebe; color: #821e14; border-radius: .4rem; font-size: .86rem; margin-block: .3rem; }
.etiqueta-bloqueo-contacto { color: #8a2a20; font-size: .83rem; font-style: italic; font-weight: 600; }
dialog { width: min(36rem, calc(100% - 2rem)); max-height: 88dvh; box-sizing: border-box; padding: 1.2rem; border: 1px solid #e4dac3; border-radius: .8rem; background: #fffaf1; color: #15372a; }
dialog::backdrop { background: #071b13a8; }
.modal-confirmar-wa { width: min(calc(100% - 1.5rem), 32rem); padding: 1.5rem; border: 2px solid #e27a6e; border-radius: .85rem; background: #fffbf9; color: #211210; box-shadow: 0 1rem 3rem rgba(80, 20, 10, .25); }
.caja-confirmar-wa { display: flex; flex-direction: column; gap: .9rem; text-align: center; }
.icono-alerta-wa { font-size: 2.5rem; line-height: 1; }
.caja-confirmar-wa h3 { margin: 0; font-size: 1.25rem; color: #8a2016; }
.advertencia-wa { margin: 0; font-size: .95rem; line-height: 1.4; color: #3b1915; }
.detalle-advertencia-wa { margin: 0; font-size: .85rem; line-height: 1.35; color: #69433e; background: #fdedea; padding: .65rem .85rem; border-radius: .4rem; border-left: 3px solid #d45b4e; text-align: left; }
.acciones-modal-wa { display: flex; flex-direction: column; gap: .6rem; margin-top: .5rem; }
.boton-forzar-wa { background: transparent; border: 1px solid #d45b4e; color: #8a2016; padding: .5rem .8rem; font-size: .85rem; border-radius: .45rem; cursor: pointer; transition: background .15s; }
.boton-forzar-wa:hover { background: #fbe6e3; }
</style>
