export function normalizarNumeroWhatsApp(telefono) {
  if (!telefono || typeof telefono !== 'string') return ''
  let limpio = telefono.replace(/[\s\-\(\)\.]+/g, '').trim()
  if (limpio.startsWith('+')) limpio = limpio.slice(1)
  if (limpio.startsWith('00')) limpio = limpio.slice(2)
  if (limpio.startsWith('0')) limpio = limpio.slice(1)

  // Caso Argentina: si ya tiene 54
  if (limpio.startsWith('54')) {
    const resto = limpio.slice(2)
    if (resto.startsWith('9')) {
      // 549... Verificar si tiene 15 adentro tras código de área
      return '54' + resto.replace(/^(9\d{2,4})15(\d{6,8})$/, '$1$2')
    }
    // 54... falta el 9 para móvil
    return '549' + resto.replace(/^(\d{2,4})15(\d{6,8})$/, '$1$2')
  }

  // Si tiene prefijo 15 interno e.g. 261156123456 -> 2616123456
  limpio = limpio.replace(/^(\d{2,4})15(\d{6,8})$/, '$1$2')

  // Si tiene 10 dígitos (ej. 2615123456 o 1151234567)
  if (/^\d{10}$/.test(limpio)) {
    return '549' + limpio
  }

  // Si ya tiene más de 10 dígitos y no tiene prefijo país, asumimos Argentina si arranca con código de área conocido
  if (/^261\d{7}$/.test(limpio)) {
    return '549' + limpio
  }

  return limpio
}

export function enlaceWhatsApp(telefono, mensaje = '') {
  const numero = normalizarNumeroWhatsApp(telefono)
  if (!numero) return null
  const base = `https://wa.me/${numero}`
  if (!mensaje) return base
  return `${base}?text=${encodeURIComponent(mensaje)}`
}
