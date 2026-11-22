<script setup>
import { computed } from 'vue'
import lotoUrl from './image/favicon.svg?url'

const props = defineProps({
  dias: { type: Array, required: true },
  fecha: { type: String, required: true },
  zona: { type: String, required: true },
  ocupado: Boolean,
})
const emit = defineEmits(['seleccionar', 'ver-turno'])
const fechaLocal = computed(() => new Intl.DateTimeFormat('sv-SE', { timeZone: props.zona }))
const horaLocal = computed(() => new Intl.DateTimeFormat('es-AR', {
  timeZone: props.zona, hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
}))
const hora = valor => horaLocal.value.format(new Date(valor))

function minutos(valor, dia) {
  const instante = new Date(valor)
  const fechaInstante = fechaLocal.value.format(instante)
  if (fechaInstante < dia) return 0
  if (fechaInstante > dia) return 1440
  const [h, m] = horaLocal.value.format(instante).split(':').map(Number)
  return h * 60 + m
}

const columnas = computed(() => props.dias.map(dia => {
  const finales = []
  const eventos = dia.turnos.filter(turno => !turno.todo_el_dia).map(turno => ({
    turno, inicio: minutos(turno.inicio, dia.clave), fin: minutos(turno.fin, dia.clave),
  })).sort((a, b) => a.inicio - b.inicio || b.fin - a.fin)
  let grupo = []
  let finGrupo = 0
  for (const evento of eventos) {
    if (evento.inicio >= finGrupo) {
      grupo = []
      finales.length = 0
    }
    const libre = finales.findIndex(fin => fin <= evento.inicio)
    evento.carril = libre < 0 ? finales.length : libre
    finales[evento.carril] = evento.fin
    grupo.push(evento)
    for (const item of grupo) item.carriles = finales.length
    finGrupo = Math.max(finGrupo, evento.fin)
  }
  return { ...dia, eventos, jornada: dia.turnos.filter(turno => turno.todo_el_dia) }
}))

const escala = computed(() => {
  let desde = 9 * 60
  let hasta = 20 * 60
  for (const dia of columnas.value) {
    for (const intervalo of [...dia.eventos, ...(dia.franjasAtencion ?? [])]) {
      desde = Math.min(desde, Math.floor(intervalo.inicio / 60) * 60)
      hasta = Math.max(hasta, Math.ceil(intervalo.fin / 60) * 60)
    }
  }
  return { desde, hasta, horas: (hasta - desde) / 60 }
})
const marcas = computed(() => Array.from({ length: escala.value.horas }, (_, index) =>
  String(escala.value.desde / 60 + index).padStart(2, '0') + ':00',
))
const hayJornada = computed(() => columnas.value.some(dia => dia.jornada.length))
const tipo = turno => turno.bloqueado ? 'bloqueo' : turno.esPreparacion ? 'preparacion' : turno.equipo_incompleto ? 'equipo' : 'turno'
const titulo = turno => turno.bloqueado ? 'No disponible' : turno.esPreparacion ? 'Preparación' : turno.cliente
const detalle = turno => turno.bloqueado ? turno.profesionales : turno.esPreparacion ? turno.espacio : turno.servicio

function tramosSinAtencion(franjas) {
  if (franjas === null) return []
  const { desde, hasta } = escala.value
  let comienzo = desde
  const tramos = []
  for (const franja of [...franjas].sort((a, b) => a.inicio - b.inicio)) {
    if (franja.fin <= comienzo) continue
    if (franja.inicio > comienzo) tramos.push({ inicio: comienzo, fin: Math.min(hasta, franja.inicio) })
    comienzo = Math.max(comienzo, franja.fin)
    if (comienzo >= hasta) break
  }
  if (comienzo < hasta) tramos.push({ inicio: comienzo, fin: hasta })
  return tramos
}

function posicionVertical(intervalo) {
  const total = escala.value.hasta - escala.value.desde
  return {
    top: 100 * (intervalo.inicio - escala.value.desde) / total + '%',
    height: 100 * Math.max(1, intervalo.fin - intervalo.inicio) / total + '%',
  }
}

function textoMinutos(minutos) {
  return String(Math.floor(minutos / 60)).padStart(2, '0') + ':' + String(Math.floor(minutos % 60)).padStart(2, '0')
}

function posicion(evento) {
  return {
    ...posicionVertical(evento),
    left: 100 * evento.carril / evento.carriles + '%',
    width: 100 / evento.carriles + '%',
  }
}
</script>

<template>
  <div class="desplazamiento" tabindex="0" aria-label="Calendario por hora">
    <div class="grilla" :class="{ unDia: columnas.length === 1 }"
      :style="{ '--columnas': columnas.length, '--horas': escala.horas }">
      <div class="esquina" aria-hidden="true"></div>
      <button v-for="dia in columnas" :key="dia.clave" class="dia" type="button"
        :aria-pressed="fecha === dia.clave" :disabled="ocupado" @click="emit('seleccionar', dia.clave)">
        <span>{{ dia.nombre }}</span><strong>{{ dia.numero }} <img v-if="dia.franjasAtencion?.length === 0" class="icono-loto" :src="lotoUrl" alt="Sin atención"></strong>
      </button>
      <template v-if="hayJornada">
        <span class="jornada-label">Todo el día</span>
        <div v-for="dia in columnas" :key="'jornada-' + dia.clave" class="jornada">
          <button v-for="turno in dia.jornada" :key="turno.id" type="button" class="bloqueo tarjeta-jornada"
            :disabled="ocupado" @click="emit('ver-turno', dia.clave, turno.id)">No disponible · {{ turno.profesionales }}</button>
        </div>
      </template>
      <div class="horas"><span v-for="marca in marcas" :key="marca">{{ marca }}</span></div>
      <div v-for="dia in columnas" :key="'horas-' + dia.clave" class="carril" :class="{ seleccionado: dia.clave === fecha }">
        <div v-for="tramo in tramosSinAtencion(dia.franjasAtencion)" :key="tramo.inicio" class="sin-atencion"
          :style="posicionVertical(tramo)" :title="`Sin atención · ${textoMinutos(tramo.inicio)}–${textoMinutos(tramo.fin)}`">
          <span class="marca-sin-atencion"><img class="icono-loto" :src="lotoUrl" alt=""> Sin atención<span class="solo-lectores"> de {{ textoMinutos(tramo.inicio) }} a {{ textoMinutos(tramo.fin) }}</span></span>
        </div>
        <button v-for="evento in dia.eventos" :key="evento.turno.id" type="button" class="tarjeta"
          :class="[tipo(evento.turno), { breve: evento.fin - evento.inicio < 36 }]" :style="posicion(evento)" :disabled="ocupado"
          :aria-label="hora(evento.turno.inicio) + ', ' + titulo(evento.turno) + ', ' + detalle(evento.turno)"
          @click="emit('ver-turno', dia.clave, evento.turno.id)">
          <time>{{ hora(evento.turno.inicio) }}</time>
          <strong>{{ titulo(evento.turno) }}</strong>
          <small>{{ detalle(evento.turno) }}</small>
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.desplazamiento { overflow: auto; border: 1px solid #ded9cc; border-radius: .75rem; }
.grilla { --alto-hora: 3.5rem; display: grid; grid-template-columns: 3.7rem repeat(var(--columnas), minmax(5.75rem, 1fr)); min-width: max-content; background: #fffcf4; }
.grilla.unDia { min-width: 0; }
.dia, .esquina { min-height: 3.25rem; background: #f1eee4; border: 0; border-bottom: 1px solid #ded9cc; border-radius: 0; color: #254739; }
.dia { display: grid; place-content: center; width: 100%; border-left: 1px solid #ded9cc; text-align: center; text-transform: capitalize; font: inherit; font-size: .74rem; cursor: pointer; }
.dia strong { font-size: 1rem; }
.dia[aria-pressed=true] { background: #174334; color: #fff6dc; }
.horas { display: grid; grid-auto-rows: var(--alto-hora); background: #f5f2e8; font-size: .71rem; font-variant-numeric: tabular-nums; }
.horas span, .jornada-label { padding: .32rem; text-align: right; }
.jornada-label { font-size: .65rem; }
.carril { height: calc(var(--horas) * var(--alto-hora)); position: relative; border-left: 1px solid #ded9cc; background: repeating-linear-gradient(to bottom, transparent 0 calc(var(--alto-hora) - 1px), #e5e0d4 calc(var(--alto-hora) - 1px) var(--alto-hora)); }
.sin-atencion { position: absolute; inset-inline: 0; display: grid; place-items: center; overflow: hidden; background: repeating-linear-gradient(135deg, #efede7 0 5px, #e5e2d9 5px 6px); color: #4e5a53; }
.marca-sin-atencion { text-align: center; font-size: .7rem; line-height: 1.12; padding: .1rem; }
.icono-loto { width: 1rem; height: 1rem; vertical-align: middle; }
.solo-lectores { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
.carril.seleccionado { background-color: #f3f5e9; }
.jornada { border-left: 1px solid #ded9cc; border-bottom: 1px solid #ded9cc; }
.tarjeta, .tarjeta-jornada { border: 1px solid #a6c6ae; border-left: 3px solid #3a7958; border-radius: .4rem; background: #dceede; color: #173726; text-align: left; font: inherit; font-size: .7rem; cursor: pointer; overflow: hidden; }
.tarjeta { position: absolute; margin: 0; padding: .15rem .2rem; display: grid; align-content: start; min-height: .75rem; }
.tarjeta-jornada { padding: .2rem; width: 100%; }
.tarjeta time { font-variant-numeric: tabular-nums; font-size: .59rem; }
.tarjeta :is(strong, small) { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.tarjeta.breve { grid-auto-flow: column; justify-content: start; gap: .18rem; align-items: baseline; }
.tarjeta.breve small { display: none; }
.bloqueo { background: #f4dddd; border-color: #ddafb1; border-left-color: #ad6265; color: #602d32; }
.preparacion { background: #e9e2ee; border-color: #ccbbd6; border-left-color: #88679d; color: #453553; }
.equipo { background: #f9eac0; border-color: #d9bb78; border-left-color: #a17428; color: #624613; }
:focus-visible { outline: 2px solid #916119; outline-offset: -2px; z-index: 1; }
</style>
