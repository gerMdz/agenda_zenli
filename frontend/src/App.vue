<script setup>
import { computed, onMounted, ref } from 'vue'
import portadaOriginal from './image/portada.webp'

const telefono = ref('+5492616356192')
const direccion = ref('Espejo 167, Ciudad de Mendoza')
const horarios = ref('Lun, mié y vie · 10:00 a 20:00\nMar, jue y sáb · 11:00 a 19:00')
const imagen = ref(portadaOriginal)
const soloImagen = ref(false)
const portadaCargada = ref(false)
const redes = ref([])
const servicios = ref([])
const servicioSeleccionado = ref(null)
const modalAbierto = ref(false)
const enlaceContacto = computed(() => `https://wa.me/${telefono.value.slice(1)}`)
const telefonoVisible = computed(() =>
  telefono.value === '+5492616356192' ? '261 635 6192' : telefono.value,
)

function abrirDetalle(servicio) {
  servicioSeleccionado.value = servicio
  modalAbierto.value = true
  document.body.style.overflow = 'hidden'
}

function cerrarModal() {
  modalAbierto.value = false
  servicioSeleccionado.value = null
  document.body.style.overflow = ''
}

onMounted(async () => {
  try {
    const respPortada = await fetch('/api/portada')
    if (respPortada.ok) {
      const portada = await respPortada.json()
      soloImagen.value = portada.solo_imagen === true
      telefono.value = portada.telefono
      direccion.value = portada.direccion
      horarios.value = portada.horarios
      if (portada.imagen) imagen.value = portada.imagen
      redes.value = [
        { nombre: 'Instagram', enlace: portada.instagram },
        { nombre: 'Facebook', enlace: portada.facebook },
      ].filter(red => red.enlace)
    }
  } catch {
    // Si falta la configuración, se usan los valores iniciales.
  } finally {
    portadaCargada.value = true
  }

  if (soloImagen.value) return

  try {
    const respServicios = await fetch('/api/servicios')
    if (respServicios.ok) {
      const datos = await respServicios.json()
      servicios.value = datos.servicios
    }
  } catch {
    // El contacto sigue visible aunque no se pueda cargar el catálogo.
  }
})
</script>

<template>
  <img v-if="portadaCargada && soloImagen" class="solo-imagen" :src="imagen" alt="" fetchpriority="high" @error="imagen = portadaOriginal">
  <div v-else-if="portadaCargada" class="sitio" id="inicio">
    <header class="cabecera">
      <div class="ancho cabecera-interior">
        <a class="marca" href="#inicio" aria-label="Zenli, inicio">
          <span class="loto" aria-hidden="true"><i></i></span>
          <span class="nombre-marca">Zenli</span>
          <span class="actividad">Centro de masajes</span>
        </a>
        <nav aria-label="Navegación principal">
          <a href="#inicio">Inicio</a>
          <a href="#masajes">Masajes</a>
          <a href="#metodo">Método Zenli</a>
          <a href="#nosotras">Nosotras</a>
          <a href="#ubicacion">Ubicación</a>
        </nav>
        <a class="boton-dorado contacto-cabecera" :href="enlaceContacto" aria-label="Consultas por WhatsApp"><span class="etiqueta-contacto">Consultas por </span>WhatsApp</a>
      </div>
    </header>

    <main>
      <section class="hero" aria-labelledby="titulo">
        <img :src="imagen" alt="" fetchpriority="high" @error="imagen = portadaOriginal">
        <div class="hero-texto">
          <p class="ceja">Método Zenli</p>
          <h1 id="titulo">Bienestar, equilibrio<br> y energía</h1>
          <p>Masajes que combinan técnicas tradicionales y orientales para tu bienestar integral.</p>
          <a class="boton-dorado" href="#masajes">Conocé nuestros masajes <span aria-hidden="true">↓</span></a>
        </div>
      </section>

      <section class="metodo" id="metodo" aria-label="Método Zenli">
        <div class="ancho pilares">
          <div><span class="simbolo" aria-hidden="true">❋</span><div><h2>Cuerpo</h2><p>Alivio y bienestar</p></div></div>
          <div><span class="simbolo" aria-hidden="true">✿</span><div><h2>Mente</h2><p>Pausa y claridad</p></div></div>
          <div><span class="simbolo" aria-hidden="true">✧</span><div><h2>Energía</h2><p>Equilibrio y vitalidad</p></div></div>
        </div>
      </section>

      <section id="masajes" class="ancho masajes" aria-labelledby="titulo-masajes">
        <div class="titulo-seccion">
          <span class="loto" aria-hidden="true"><i></i></span>
          <h2 id="titulo-masajes">Nuestros masajes</h2>
        </div>
        <div class="tratamientos">
          <article v-for="servicio in servicios" :key="servicio.id">
            <div class="foto-servicio">
              <img v-if="servicio.imagen_version" :src="`/api/servicios/${servicio.id}/imagen/${servicio.imagen_version}`" :alt="servicio.nombre" loading="lazy">
              <div v-else class="foto-placeholder" aria-hidden="true"><span class="simbolo-masaje">✿</span></div>
            </div>
            <div class="tratamiento-texto">
              <h3>{{ servicio.nombre }}</h3>
              <p>{{ servicio.descripcion_corta }}</p>
              <button v-if="servicio.detalle" type="button" class="btn-detalle" @click="abrirDetalle(servicio)">Ver detalle</button>
            </div>
          </article>
        </div>
      </section>

      <!-- Modal de detalle -->
      <Teleport to="body">
        <div v-if="modalAbierto && servicioSeleccionado" class="modal-overlay" @click.self="cerrarModal" role="dialog" aria-modal="true" aria-labelledby="modal-titulo">
          <div class="modal-contenido">
            <button type="button" class="modal-cerrar" aria-label="Cerrar" @click="cerrarModal">✕</button>
            <img v-if="servicioSeleccionado.imagen_version" :src="`/api/servicios/${servicioSeleccionado.id}/imagen/${servicioSeleccionado.imagen_version}`" :alt="servicioSeleccionado.nombre" class="modal-imagen">
            <h2 id="modal-titulo" class="modal-titulo">{{ servicioSeleccionado.nombre }}</h2>
            <p class="modal-desc">{{ servicioSeleccionado.descripcion_corta }}</p>
            <div v-if="servicioSeleccionado.detalle" class="modal-detalle">{{ servicioSeleccionado.detalle }}</div>
            <div class="modal-accion">
              <a class="boton-dorado" :href="enlaceContacto">Reservar turno <span aria-hidden="true">↗</span></a>
            </div>
          </div>
        </div>
      </Teleport>

      <section id="nosotras" class="ancho" aria-labelledby="titulo-nosotras">
        <div class="nosotras">
          <span class="loto" aria-hidden="true"><i></i></span>
          <div>
            <h2 id="titulo-nosotras">Nataly y Micaela</h2>
            <p>Somos las profesionales de Zenli, nuestro centro de masajes en Mendoza.</p>
          </div>
          <a class="boton-dorado" :href="enlaceContacto">Escribinos <span aria-hidden="true">↗</span></a>
        </div>
      </section>

      <section id="ubicacion" class="ancho datos" aria-label="Ubicación, horarios y consultas">
        <div v-if="direccion">
          <h2>Ubicación</h2>
          <address><a :href="`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(direccion)}`" target="_blank" rel="noopener noreferrer">{{ direccion }} ↗</a></address>
        </div>
        <div v-if="horarios">
          <h2>Horarios del centro</h2>
          <p class="horarios">{{ horarios }}</p>
        </div>
        <div>
          <h2>Consultas</h2>
          <a class="telefono" :href="enlaceContacto" target="_blank" rel="noopener noreferrer">{{ telefonoVisible }} ↗</a>
          <ul v-if="redes.length" class="redes" aria-label="Redes sociales">
            <li v-for="red in redes" :key="red.nombre"><a :href="red.enlace" target="_blank" rel="noopener noreferrer">{{ red.nombre }} ↗</a></li>
          </ul>
        </div>
      </section>
    </main>

    <footer class="pie"><div class="ancho">Zenli · Centro de masajes</div></footer>
  </div>
</template>

<style scoped>
.solo-imagen {
  display: block;
  width: 100%;
  height: 100vh;
  height: 100dvh;
  object-fit: cover;
}

.sitio {
  --verde: #092b20;
  --verde-oscuro: #061b14;
  --oro: #e9bf68;
  --oro-oscuro: #79571c;
  --borde: #e5dbc5;
  color: var(--verde-oscuro);
  background: #faf5e9;
  font-family: system-ui, sans-serif;
  line-height: 1.55;
}

.ancho { width: min(100% - 3rem, 75rem); margin-inline: auto; }
.cabecera { background: var(--verde-oscuro); color: #fff6e5; border-bottom: 1px solid #e9bf6838; }
.cabecera-interior { display: flex; align-items: center; justify-content: space-between; gap: 2rem; padding-block: .8rem; }
.marca { display: grid; justify-items: center; color: var(--oro); text-decoration: none; }
.nombre-marca { font: 400 2.75rem/.9 Georgia, serif; }
.actividad { font-size: .61rem; letter-spacing: .125em; text-transform: uppercase; white-space: nowrap; margin-top: .32rem; color: #fff6e5; }
.loto { width: 2.2rem; height: 1.2rem; display: inline-block; position: relative; color: inherit; }
.loto::before, .loto::after, .loto i { content: ''; position: absolute; bottom: .1rem; left: 50%; width: .78rem; height: .78rem; border: 1.25px solid currentColor; border-radius: 85% 0 85% 0; }
.loto i { transform: translateX(-50%) rotate(-45deg); bottom: .26rem; }
.loto::before { transform: translateX(-105%) rotate(-78deg); }
.loto::after { transform: translateX(4%) rotate(-12deg); }
nav { display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: .65rem 1.65rem; }
nav a { color: inherit; font-size: .9rem; text-decoration: none; text-underline-offset: .32rem; }
nav a:hover { text-decoration: underline; color: var(--oro); }
.boton-dorado { display: inline-flex; gap: .65rem; align-items: center; justify-content: center; width: fit-content; padding: .73rem 1.24rem; border-radius: 2rem; color: var(--verde-oscuro); background: #eac26f; font-size: .91rem; font-weight: 700; text-decoration: none; box-shadow: inset 0 1px #fff3c9; }
.boton-dorado:hover { background: #f3d28b; }
.contacto-cabecera { display: inline-block; white-space: nowrap; }
.hero { position: relative; isolation: isolate; display: grid; place-items: center; min-height: clamp(29rem, 39vw, 36rem); text-align: center; color: #fff8e7; background: var(--verde-oscuro); }
.hero img, .hero::after { position: absolute; inset: 0; width: 100%; height: 100%; }
.hero img { object-fit: cover; z-index: -2; }
.hero::after { content: ''; z-index: -1; background: radial-gradient(ellipse at center, #061811aa, #04150c22 85%), linear-gradient(#06181130, #06181150); }
.hero-texto { padding: 3rem 1.25rem; max-width: 51rem; }
.ceja { display: flex; align-items: center; justify-content: center; gap: .85rem; color: #f3d18c; letter-spacing: .22em; text-transform: uppercase; font-size: .76rem; font-weight: 650; }
.ceja::before, .ceja::after { content: ''; width: clamp(1.5rem, 5vw, 4rem); height: 1px; background: var(--oro); }
.hero h1 { margin: .6rem 0 1rem; font: italic 500 clamp(2.9rem, 6vw, 5.4rem)/1.03 Georgia, serif; text-shadow: 0 2px 20px #0009; }
.hero-texto > p:nth-of-type(2) { max-width: 25rem; margin: 0 auto 1.5rem; font-size: clamp(1rem, 2vw, 1.2rem); }
.metodo { border-block: 1px solid var(--borde); background: #f8efdc; }
.pilares { display: grid; grid-template-columns: repeat(3, 1fr); padding-block: 1.3rem; }
.pilares > div { display: flex; justify-content: center; align-items: center; gap: .85rem; padding-inline: .7rem; }
.pilares > div + div { border-left: 1px solid var(--borde); }
.simbolo { display: grid; place-items: center; flex: 0 0 2.8rem; height: 2.8rem; border: 1px solid #e3ca91; border-radius: 50%; background: #fef8e9; color: var(--oro-oscuro); font-size: 1.6rem; }
.pilares h2, .pilares p { margin: 0; }
.pilares h2 { font: bold 1.28rem Georgia, serif; }
.pilares p { font-size: .89rem; }
.masajes { padding-block: 3rem; }
.titulo-seccion { display: grid; justify-items: center; color: var(--oro-oscuro); margin-bottom: 1.55rem; }
.titulo-seccion h2 { display: flex; align-items: center; gap: clamp(.65rem, 2vw, 1.3rem); margin: .25rem 0 0; color: var(--verde-oscuro); text-align: center; font: 600 clamp(1.9rem, 4vw, 3rem)/1.1 Georgia, serif; }
.titulo-seccion h2::before, .titulo-seccion h2::after { content: ''; width: clamp(1rem, 7vw, 5rem); height: 1px; background: #b9934f; }
.tratamientos { display: flex; flex-wrap: wrap; justify-content: center; gap: 1rem; }
.tratamientos article { box-sizing: border-box; width: calc((100% - 3rem) / 4); overflow: hidden; border: 1px solid var(--borde); border-radius: 1rem; background: #fffaf0; text-align: center; box-shadow: 0 4px 12px #38281209; display: flex; flex-direction: column; }
.foto-servicio { position: relative; overflow: hidden; aspect-ratio: 1.6; background: #163426; }
.foto-servicio img { width: 100%; height: 100%; object-fit: cover; }
.foto-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #163426, #092b20); }
.simbolo-masaje { font-size: 3rem; color: #e9bf6855; }
.tratamiento-texto { padding: 1rem .75rem 1.15rem; display: flex; flex-direction: column; gap: .5rem; flex: 1; }
.tratamiento-texto h3 { margin: 0; font: bold 1.18rem Georgia, serif; }
.tratamiento-texto p { margin: 0; font-size: .9rem; color: #344238; }
.btn-detalle { margin-top: auto; border: 1px solid var(--oro-oscuro); background: transparent; color: var(--oro-oscuro); padding: .4rem .8rem; border-radius: .4rem; font-size: .82rem; font-weight: 600; cursor: pointer; transition: background .2s, color .2s; }
.btn-detalle:hover { background: var(--oro-oscuro); color: #fffaf0; }

/* Modal */
.modal-overlay { position: fixed; inset: 0; background: rgba(6, 27, 20, .75); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 1rem; }
.modal-contenido { position: relative; background: #fffaf0; border-radius: 1rem; max-width: 42rem; width: 100%; max-height: 90vh; overflow-y: auto; padding: 2rem; box-shadow: 0 8px 32px rgba(0,0,0,.3); }
.modal-cerrar { position: absolute; top: .75rem; right: .75rem; width: 2rem; height: 2rem; border: none; background: transparent; font-size: 1.2rem; cursor: pointer; color: #344238; border-radius: 50%; display: grid; place-items: center; }
.modal-cerrar:hover { background: #f0e6cc; }
.modal-imagen { width: 100%; max-height: 20rem; object-fit: cover; border-radius: .5rem; margin-bottom: 1rem; }
.modal-titulo { margin: 0 0 .5rem; font: 600 1.8rem Georgia, serif; color: var(--verde-oscuro); }
.modal-desc { margin: 0 0 1rem; font-size: 1rem; color: #344238; font-style: italic; }
.modal-detalle { margin: 0 0 1.5rem; font-size: .95rem; line-height: 1.65; color: #344238; white-space: pre-wrap; }
.modal-accion { text-align: center; }
.nosotras { display: flex; align-items: center; gap: 1.2rem; padding: 1.45rem clamp(1rem, 4vw, 2.8rem); border-radius: 1rem; background: var(--verde); color: #fff6e5; }
.nosotras > .loto { flex: 0 0 2.2rem; color: var(--oro); }
.nosotras h2 { font: 600 1.6rem Georgia, serif; margin: 0 0 .2rem; color: #f6d995; }
.nosotras p { margin: 0; }
.nosotras a { margin-left: auto; flex-shrink: 0; }
.datos { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.25rem; padding-block: 2.4rem; }
.datos h2 { margin: 0 0 .25rem; font: 700 1.18rem Georgia, serif; }
.datos a { color: inherit; text-underline-offset: .2em; }
.datos address { font-style: normal; }
.datos p { margin: 0; }
.horarios { white-space: pre-line; }
.telefono { font-weight: 650; }
.redes { display: flex; flex-wrap: wrap; gap: .35rem 1rem; padding: 0; margin: .5rem 0 0; list-style: none; }
.pie { padding-block: 1.1rem; background: var(--verde-oscuro); color: #f4dfb2; font-size: .82rem; }
a:focus-visible { outline: 2px solid #a87a2a; outline-offset: 4px; }

@media (max-width: 55rem) {
  .cabecera-interior { flex-wrap: wrap; gap: .8rem 1rem; }
  nav { order: 1; width: 100%; padding: .4rem 0 .2rem; border-top: 1px solid #e9bf6838; }
}

@media (max-width: 43rem) {
  .ancho { width: calc(100% - 2rem); }
  .etiqueta-contacto { display: none; }
  nav { gap: .42rem .72rem; }
  nav a { font-size: .8rem; }
  .hero img { object-position: 73% center; }
  .tratamientos { gap: .65rem; }
  .tratamientos article { width: calc((100% - .65rem) / 2); }
  .modal-contenido { padding: 1.25rem; }
  .modal-titulo { font-size: 1.4rem; }
  .pilares > div { flex-direction: column; gap: .35rem; text-align: center; padding-inline: .25rem; }
  .pilares p { font-size: .78rem; }
  .simbolo { width: 2.4rem; height: 2.4rem; flex-basis: auto; }
  .nosotras { align-items: flex-start; flex-wrap: wrap; }
  .nosotras > .loto { display: none; }
  .nosotras a { margin-left: 0; }
  .datos { grid-template-columns: 1fr; }
}
</style>
