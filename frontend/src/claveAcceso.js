export const admiteClavesAcceso = window.isSecureContext
    && typeof window.PublicKeyCredential?.parseCreationOptionsFromJSON === 'function'
    && typeof window.PublicKeyCredential?.parseRequestOptionsFromJSON === 'function'

export async function usarClaveAcceso(opciones, crear = false) {
    const publicKey = crear
        ? PublicKeyCredential.parseCreationOptionsFromJSON(opciones)
        : PublicKeyCredential.parseRequestOptionsFromJSON(opciones)
    try {
        const credencial = crear
            ? await navigator.credentials.create({ publicKey })
            : await navigator.credentials.get({ publicKey })
        if (!credencial) throw new Error('No se obtuvo una clave de acceso.')
        return credencial.toJSON()
    } catch (fallo) {
        if (fallo.name === 'NotAllowedError') {
            throw new Error('El desbloqueo se canceló o no hay una clave disponible. Podés volver a intentar.')
        }
        if (fallo.name === 'InvalidStateError') {
            throw new Error('Ya hay una clave de esta cuenta guardada en el autenticador.')
        }
        throw fallo
    }
}
