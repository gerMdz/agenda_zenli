let apertura

export function canalAgendaLocal() {
  return typeof BroadcastChannel === 'function' ? new BroadcastChannel('zenli-agenda-sesion') : null
}

function base() {
  apertura ??= new Promise((resolver, rechazar) => {
    const solicitud = indexedDB.open('zenli-agenda', 1)
    solicitud.onupgradeneeded = () => {
      solicitud.result.createObjectStore('copia')
      const altas = solicitud.result.createObjectStore('altas', { keyPath: 'id' })
      altas.createIndex('cuenta', 'cuenta')
    }
    solicitud.onsuccess = () => resolver(solicitud.result)
    solicitud.onerror = () => rechazar(solicitud.error)
  })
  return apertura
}

async function operar(almacen, modo, operacion) {
  const bd = await base()
  return new Promise((resolver, rechazar) => {
    const transaccion = bd.transaction(almacen, modo)
    const solicitud = operacion(transaccion.objectStore(almacen))
    transaccion.oncomplete = () => resolver(solicitud.result)
    transaccion.onabort = () => rechazar(transaccion.error)
    transaccion.onerror = () => rechazar(transaccion.error)
  })
}

export const leerCopia = () => operar('copia', 'readonly', tabla => tabla.get('agenda'))
export const guardarCopia = copia => operar('copia', 'readwrite', tabla => tabla.put(copia, 'agenda'))
export const borrarCopia = () => operar('copia', 'readwrite', tabla => tabla.delete('agenda'))
export async function leerAltas(cuenta) {
  const altas = await operar('altas', 'readonly', tabla => tabla.index('cuenta').getAll(cuenta))
  return altas.sort((a, b) => (a.creada ?? 0) - (b.creada ?? 0))
}
export const guardarAlta = alta => operar('altas', 'readwrite', tabla => tabla.put(JSON.parse(JSON.stringify(alta))))
export const borrarAlta = id => operar('altas', 'readwrite', tabla => tabla.delete(id))

export async function borrarDatosLocales(cuenta) {
  const bd = await base()
  return new Promise((resolver, rechazar) => {
    const transaccion = bd.transaction(['copia', 'altas'], 'readwrite')
    transaccion.objectStore('copia').delete('agenda')
    const altas = transaccion.objectStore('altas')
    const cursor = altas.index('cuenta').openCursor(cuenta)
    cursor.onsuccess = () => {
      const fila = cursor.result
      if (!fila) return
      fila.delete()
      fila.continue()
    }
    transaccion.oncomplete = resolver
    transaccion.onabort = () => rechazar(transaccion.error)
  })
}

export async function avisarCambioCuenta(cuenta) {
  const copia = await leerCopia().catch(() => null)
  if (copia && copia.usuario.id !== cuenta) await borrarCopia()
  const canal = canalAgendaLocal()
  canal?.postMessage(cuenta)
  canal?.close()
}
