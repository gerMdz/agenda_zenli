<script setup>
import { computed } from 'vue'

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
    for (const evento of dia.eventos) {
      desde = Math.min(desde, Math.floor(evento.inicio / 60) * 60)
      hasta = Math.max(hasta, Math.ceil(evento.fin / 60) * 60)
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

function posicion(evento) {
  const total = escala.value.hasta - escala.value.desde
  return {
    top: 100 * (evento.inicio - escala.value.desde) / total + '%',
    height: 100 * Math.max(1, evento.fin - evento.inicio) / total + '%',
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
        <span>{{ dia.nombre }}</span><strong>{{ dia.numero }}</strong>
      </button>
      <template v-if="hayJornada">
        <span class="jornada-label">Todo el día</span>
        <div v-for="dia in columnas" :key="'jornada-' + dia.clave" class="jornada">
          <button v-for="turno in dia.jornada" :key="turno.id" type="button" class="bloqueo tarjeta-jornada"
            :disabled="ocupado" @click="emit('ver-turno', dia.clave, turno.id)">No disponible · {{ turno.profesionales }}</button>
        </div>
      </template>
      <div class="horas"><span v-for="marca in marcas" :key="marca">{{ marca }}</span></div>
      <div v-for="dia in columnas" :key="'horas-' + dia.clave" class="carril" :class="{ seleccionado: dia.clave === fecha, cerrado: dia.sinAtencion }">
        <span v-if="dia.sinAtencion && !dia.eventos.length" class="sin-atencion">Sin atención</span>
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
.carril.cerrado { background: repeating-linear-gradient(135deg, #efede7 0 5px, #e9e7e1 5px 6px); }
.sin-atencion { position: absolute; inset: 45% .2rem auto; text-align: center; font-size: .7rem; color: #5b625e; }
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
