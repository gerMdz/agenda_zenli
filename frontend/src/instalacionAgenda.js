import { ref } from 'vue'

export const agendaInstalada = ref(false)
let invitacion = null

export function prepararInstalacionAgenda() {
  const modo = window.matchMedia('(display-mode: standalone)')
  const actualizarModo = () => {
    agendaInstalada.value = modo.matches || navigator.standalone === true
  }
  actualizarModo()
  modo.addEventListener('change', actualizarModo)
  window.addEventListener('beforeinstallprompt', evento => {
    evento.preventDefault()
    invitacion = evento
  })
  window.addEventListener('appinstalled', () => {
    invitacion = null
    agendaInstalada.value = true
  })
}

export async function solicitarInstalacionAgenda() {
  const evento = invitacion
  invitacion = null
  if (!evento) return false

  try {
    const { outcome } = await evento.prompt()
    if (outcome === 'accepted') agendaInstalada.value = true
    return true
  } catch {
    return false
  }
}
