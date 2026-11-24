<script setup>
import { ref } from 'vue'
import { gruposMenu } from './navegacionAdministracion.js'

defineProps({
  usuario: { type: Object, default: null },
  soloAgenda: Boolean,
  seccion: { type: String, required: true },
})

const menuAbierto = ref(false)
</script>

<template>
  <div class="estructura" :class="{ autenticada: usuario }">
    <aside v-if="usuario" class="menu">
      <button type="button" class="boton-menu" aria-controls="navegacion-admin" :aria-expanded="menuAbierto" @click="menuAbierto = !menuAbierto">
        <span aria-hidden="true">☰</span> {{ menuAbierto ? 'Cerrar menú' : 'Menú' }}
      </button>
      <nav id="navegacion-admin" :class="{ abierto: menuAbierto }" aria-label="Menú de administración">
        <div class="grupo-menu">
          <a href="/admin/agenda#turnos" class="enlace-agenda" :aria-current="seccion === 'agenda' ? 'page' : undefined" @click="menuAbierto = false">
            {{ usuario.admin ? 'Agenda' : 'Mi agenda' }}
          </a>
          <template v-if="usuario.profesional_id && !soloAgenda">
            <a href="/admin/agenda#mis-horarios" :aria-current="seccion === 'mis-horarios' ? 'page' : undefined" @click="menuAbierto = false">Mis horarios</a>
            <a href="/admin/agenda#horarios-no-disponibles" :aria-current="seccion === 'horarios-no-disponibles' ? 'page' : undefined" @click="menuAbierto = false">Horarios no disponibles</a>
          </template>
          <a v-if="!soloAgenda" href="/admin/agenda#clientes" :aria-current="seccion === 'clientes' ? 'page' : undefined" @click="menuAbierto = false">Clientes</a>
          <a v-if="!soloAgenda" href="/admin/agenda#mensajes" :aria-current="seccion === 'mensajes' ? 'location' : undefined" @click="menuAbierto = false">Mensajes privados</a>
        </div>
        <template v-if="usuario.admin && !soloAgenda">
          <div v-for="grupo in gruposMenu" :key="grupo.nombre" class="grupo-menu">
            <p>{{ grupo.nombre }}</p>
            <a v-for="item in grupo.enlaces" :key="item.id" :href="'/admin#' + item.id"
              :aria-current="seccion === item.id ? 'page' : undefined" @click="menuAbierto = false">
              {{ item.nombre }}
            </a>
          </div>
        </template>
      </nav>
    </aside>
    <slot />
  </div>
</template>

<style scoped>
.estructura { width: min(calc(100% - 2rem), 80rem); margin-inline: auto; }
.estructura.autenticada { display: grid; grid-template-columns: minmax(12rem, 15rem) minmax(0, 1fr); gap: clamp(1rem, 3vw, 2.4rem); align-items: start; }
:slotted(main) { min-width: 0; }
.menu { position: sticky; top: 1rem; margin-block: clamp(1.25rem, 4vw, 2.6rem); max-height: calc(100dvh - 2rem); overflow-y: auto; }
.menu nav { display: grid; gap: .85rem; padding: .85rem; border: 1px solid #e4dac3; border-radius: .9rem; background: #fffaf1; }
.menu nav a { display: block; padding: .52rem .65rem; border-radius: .45rem; color: #214d3a; font-size: .9rem; text-decoration: none; line-height: 1.3; }
.menu nav a:hover { background: #f5ecda; }
.menu nav a[aria-current] { background: #dceadf; color: #143c2a; font-weight: 750; }
.menu .enlace-agenda { font-weight: 700; }
.grupo-menu p { margin: 0 0 .2rem .65rem; color: #526255; font-size: .69rem; letter-spacing: .09em; text-transform: uppercase; font-weight: 700; }
.boton-menu { display: none; padding: .56rem .85rem; border: 1px solid #214d3a; border-radius: .4rem; background: #fff9ec; color: #214d3a; cursor: pointer; font: inherit; font-weight: 650; }
.menu :is(a, button):focus-visible { outline: 2px solid #8f631e; outline-offset: 2px; }
@media (max-width: 46rem) {
  .estructura.autenticada { display: block; }
  .menu { position: static; margin-block: .75rem 0; max-height: none; overflow: visible; }
  .menu nav { display: none; margin-top: .45rem; }
  .menu nav.abierto { display: grid; }
  .boton-menu { display: inline-flex; gap: .5rem; align-items: center; width: 100%; justify-content: center; }
}
</style>
