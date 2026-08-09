/**
 * UUID v4 que funciona TAMBIÉN fuera de un contexto seguro.
 *
 * `crypto.randomUUID()` sólo existe cuando `window.isSecureContext` es true —
 * es decir, HTTPS o `localhost`. Servida por IP sobre HTTP (`http://192.168.x.x:5189`,
 * que es justo como se trabaja este proyecto en red local, y también cómo se ve una
 * demo o un staging sin certificado) la función NO está definida y llamarla lanza
 * `TypeError: crypto.randomUUID is not a function`.
 *
 * Eso rompió dos flujos críticos en silencio: el REGISTRO (`useSession.register`
 * generaba el id de deduplicación antes de llamar a la API) y el CHECKOUT
 * (`trackEvent` lo genera antes de pedir la sesión de pago). En ambos el error
 * caía en un `catch` y el usuario sólo veía que su clic no hacía nada.
 *
 * `crypto.getRandomValues` SÍ está disponible sin contexto seguro, así que el
 * respaldo mantiene la misma calidad de aleatoriedad; sólo se recurre a
 * `Math.random` si tampoco existiera.
 */
export function randomId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }

  const bytes = new Uint8Array(16)

  if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
    crypto.getRandomValues(bytes)
  } else {
    for (let i = 0; i < bytes.length; i += 1) {
      bytes[i] = Math.floor(Math.random() * 256)
    }
  }

  // Marcas de versión (4) y variante (10xx) que exige RFC 4122.
  bytes[6] = (bytes[6] & 0x0f) | 0x40
  bytes[8] = (bytes[8] & 0x3f) | 0x80

  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')

  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}
