<script setup>
import { computed, onMounted, ref } from 'vue'

const props = defineProps({
  seccion: { type: String, required: true },
  consultar: { type: Function, required: true },
})

const horario = ref(null)
const cargando = ref(true)
const error = ref('')
const dias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo']
const formatoFecha = new Intl.DateTimeFormat('es-AR', { dateStyle: 'long', timeZone: 'UTC' })
const fecha = valor => formatoFecha.format(new Date(valor + 'T00:00:00Z'))
const horas = fila => `${fila.hora_inicio.slice(0, 5)}–${fila.hora_fin.slice(0, 5)}`
const franjas = computed(() => horario.value?.por_franjas ? horario.value.franjas : [])
const semanales = computed(() => franjas.value
  .filter(franja => franja.vigente_desde !== franja.vigente_hasta)
  .sort((a, b) => a.dia_semana - b.dia_semana || a.hora_inicio.localeCompare(b.hora_inicio)))
const puntuales = computed(() => [
  ...franjas.value.filter(franja => franja.vigente_desde === franja.vigente_hasta)
    .map(franja => ({ ...franja, fecha: franja.vigente_desde })),
  ...(horario.value?.excepciones ?? []).filter(fila => fila.tipo === 'especial'),
].sort((a, b) => a.fecha.localeCompare(b.fecha) || a.hora_inicio.localeCompare(b.hora_inicio)))
const noDisponibles = computed(() => (horario.value?.excepciones ?? []).filter(fila => fila.tipo === 'bloqueo'))
const consultarHorarios = computed(() => props.seccion === 'mis-horarios')

onMounted(async () => {
  try {
    // Sin parámetros, la API toma el perfil de la sesión.
    const respuesta = await props.consultar('/api/admin/agenda/turnos/horario-habitual')
    horario.value = respuesta.horario
  } catch {
    error.value = 'No pudimos cargar tus horarios. Volvé a abrir la sección para intentar de nuevo.'
  } finally {
    cargando.value = false
  }
})
</script>

<template>
  <section class="horarios-personales" aria-labelledby="titulo-horarios-personales">
    <h1 id="titulo-horarios-personales">{{ consultarHorarios ? 'Mis horarios' : 'Horarios no disponibles' }}</h1>
    <p>Los cambios se gestionan desde administración.</p>
    <p v-if="cargando" role="status">Cargando horarios…</p>
    <p v-else-if="error" role="alert">{{ error }}</p>
    <template v-else-if="consultarHorarios">
      <h2>Horario semanal</h2>
      <p v-if="!horario?.por_franjas">No tenés un horario semanal definido.</p>
      <p v-else-if="!semanales.length">No hay franjas semanales registradas.</p>
      <ul v-else>
        <li v-for="(fila, indice) in semanales" :key="indice">
          <strong>{{ dias[fila.dia_semana - 1] }} · {{ horas(fila) }}</strong>
          <small>Desde el {{ fecha(fila.vigente_desde) }}<template v-if="fila.vigente_hasta"> hasta el {{ fecha(fila.vigente_hasta) }}</template></small>
        </li>
      </ul>
      <h2>Fechas puntuales</h2>
      <p v-if="!puntuales.length">No hay horarios para fechas puntuales.</p>
      <ul v-else>
        <li v-for="(fila, indice) in puntuales" :key="indice">
          <strong>{{ fecha(fila.fecha) }}</strong>
          <span>{{ horas(fila) }}</span>
        </li>
      </ul>
    </template>
    <template v-else>
      <p v-if="!noDisponibles.length">No hay horarios no disponibles registrados.</p>
      <ul v-else>
        <li v-for="(fila, indice) in noDisponibles" :key="indice">
          <strong>{{ fecha(fila.fecha) }}</strong>
          <span>{{ fila.hora_inicio === '00:00:00' && fila.hora_fin === '24:00:00' ? 'Todo el día' : horas(fila) }}</span>
        </li>
      </ul>
    </template>
  </section>
</template>

<style scoped>
.horarios-personales { padding: clamp(1rem, 3vw, 2rem); background: #fffaf1; border: 1px solid #e4dac3; border-radius: .9rem; }
.horarios-personales h1 { margin: 0; }
.horarios-personales h2 { margin-block: 1.5rem .6rem; }
.horarios-personales ul { display: grid; gap: .5rem; padding: 0; list-style: none; }
.horarios-personales li { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: .35rem 1rem; padding: .7rem .85rem; border: 1px solid #e4dac3; border-radius: .5rem; }
.horarios-personales small { color: #526255; }
</style>
