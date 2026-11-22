const ZONA = 'America/Argentina/Mendoza'

const formatoFechaHora = new Intl.DateTimeFormat('es-AR', {
  timeZone: ZONA,
  day: 'numeric',
  month: 'numeric',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
  hourCycle: 'h23',
})

const formatoFechaCorta = new Intl.DateTimeFormat('es-AR', {
  timeZone: ZONA,
  day: 'numeric',
  month: 'numeric',
  year: 'numeric',
})

const formatoHora = new Intl.DateTimeFormat('es-AR', {
  timeZone: ZONA,
  hour: '2-digit',
  minute: '2-digit',
  hourCycle: 'h23',
})

export function formatearFechaHora(iso) {
  if (!iso) return ''
  const fecha = new Date(iso)
  if (Number.isNaN(fecha.getTime())) return ''
  return formatoFechaHora.format(fecha).replace(',', '')
}

export function formatearRangoTurno(inicioIso, finIso) {
  if (!inicioIso || !finIso) return ''
  const inicio = new Date(inicioIso)
  const fin = new Date(finIso)
  if (Number.isNaN(inicio.getTime()) || Number.isNaN(fin.getTime())) return ''
  const diaInicio = formatoFechaCorta.format(inicio)
  const diaFin = formatoFechaCorta.format(fin)
  if (diaInicio === diaFin) {
    return `${diaInicio} ${formatoHora.format(inicio)}–${formatoHora.format(fin)}`
  }
  return `${diaInicio} ${formatoHora.format(inicio)}–${diaFin} ${formatoHora.format(fin)}`
}

export function importePesos(centavos) {
  if (centavos == null || centavos === '') return ''
  const valor = String(centavos).padStart(3, '0')
  const entera = valor.slice(0, -2).replace(/\B(?=(\d{3})+(?!\d))/g, '.')
  const decimales = valor.slice(-2)
  return `${entera},${decimales}`
}

export function resumenAuditoria(auditoria, femenino = false) {
  if (!auditoria) return ''
  const creado = femenino ? 'Creada' : 'Creado'
  const modificado = femenino ? 'Modificada' : 'Modificado'
  const partes = []

  const tieneCreacion = Boolean(auditoria.creado_por || auditoria.creado_en)
  const tieneModificacion = Boolean(auditoria.actualizado_por || auditoria.actualizado_en)
  const mismaFecha =
    tieneCreacion &&
    tieneModificacion &&
    auditoria.creado_en === auditoria.actualizado_en &&
    auditoria.creado_por === auditoria.actualizado_por

  if (tieneCreacion) {
    const detalle = []
    if (auditoria.creado_por) detalle.push(`por ${auditoria.creado_por}`)
    if (auditoria.creado_en) detalle.push(`el ${formatearFechaHora(auditoria.creado_en)}`)
    partes.push(`${creado} ${detalle.join(' ')}`)
  }

  if (tieneModificacion && !mismaFecha) {
    const prefijo = tieneCreacion ? modificado : 'Última modificación'
    const detalle = []
    if (auditoria.actualizado_por) detalle.push(`por ${auditoria.actualizado_por}`)
    if (auditoria.actualizado_en) detalle.push(`el ${formatearFechaHora(auditoria.actualizado_en)}`)
    partes.push(`${prefijo} ${detalle.join(' ')}`)
  }

  return partes.join(' · ')
}
