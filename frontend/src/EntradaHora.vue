<script setup>
defineProps({ modelValue: { type: String, default: '' }, permitirMedianoche: Boolean, required: Boolean, segundos: Boolean })
const emit = defineEmits(['update:modelValue'])
</script>

<template>
  <span class="entrada-hora">
    <input :value="modelValue.startsWith('24:') ? '' : modelValue" type="time" :step="segundos ? 'any' : 60" :required="required && !modelValue.startsWith('24:')"
      :disabled="modelValue.startsWith('24:')" @input="emit('update:modelValue', $event.target.value)">
    <span v-if="permitirMedianoche" class="medianoche"><input type="checkbox" aria-label="Hasta medianoche (24:00)" :checked="modelValue.startsWith('24:')"
      @change="emit('update:modelValue', $event.target.checked ? '24:00' : '')"> Hasta medianoche (24:00)</span>
  </span>
</template>

<style scoped>
.entrada-hora { display: grid; gap: .3rem; }
.medianoche { display: flex; align-items: center; gap: .3rem; font-size: .85em; }
</style>