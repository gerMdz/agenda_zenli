<script setup>
import { computed, ref } from 'vue'

const slots = [
  { id: 'morning', label: '10:00 · Alex' },
  { id: 'afternoon', label: '16:30 · Dani' },
]
const treatment = ref('')
const selectedSlot = ref('')
const reservations = ref([])
const feedback = ref('')
const availableSlots = computed(() =>
  slots.filter(slot => !reservations.value.some(reservation => reservation.slot.id === slot.id)),
)
const canReset = computed(() =>
  Boolean(treatment.value || selectedSlot.value || reservations.value.length),
)

function reserve() {
  const slot = availableSlots.value.find(item => item.id === selectedSlot.value)
  if (!treatment.value || !slot) return

  reservations.value.push({ treatment: treatment.value, slot })
  feedback.value = `Reserva simulada de ${treatment.value} a las ${slot.label}.`
  selectedSlot.value = ''
}

function reset() {
  reservations.value = []
  treatment.value = ''
  selectedSlot.value = ''
  feedback.value = 'Demo reiniciada. Todos los horarios están disponibles.'
}
</script>

<template>
  <main class="demo">
    <a href="/">Volver a Zenli</a>
    <h1>Demo de reservas</h1>
    <p>Datos ficticios. Las reservas se borran al recargar la página.</p>

    <form @submit.prevent="reserve">
      <label for="demo-treatment">Tratamiento</label>
      <select id="demo-treatment" v-model="treatment" required>
        <option disabled value="">Elegí un tratamiento</option>
        <option>Masaje relajante</option>
        <option>Masaje descontracturante</option>
      </select>

      <label for="demo-slot">Horario de ejemplo</label>
      <select id="demo-slot" v-model="selectedSlot" :disabled="!availableSlots.length" required>
        <option disabled value="">
          {{ availableSlots.length ? 'Elegí un horario' : 'No quedan horarios disponibles' }}
        </option>
        <option v-for="slot in availableSlots" :key="slot.id" :value="slot.id">{{ slot.label }}</option>
      </select>

      <button type="submit" :disabled="!treatment || !selectedSlot">Simular reserva</button>
      <button type="button" :disabled="!canReset" @click="reset">Reiniciar demo</button>
    </form>

    <p role="status">
      {{ feedback }}
      <span v-if="!availableSlots.length">Ya no quedan horarios de ejemplo.</span>
    </p>
  </main>
</template>

<style scoped>
.demo {
  max-width: 34rem;
  margin: 3rem auto;
  padding: 0 1.25rem;
  font-family: system-ui, sans-serif;
  color: #17382e;
}

.demo form {
  display: grid;
  gap: 0.75rem;
  margin-block: 2rem;
}

.demo select,
.demo button {
  font: inherit;
  min-height: 2.75rem;
}
</style>
