/**
 * Captura del click-ID de campañas de Google (`gclid`) que el landing reenvía
 * como query param al saltar a la app (ej. CTA "Empezar gratis" cross-origin).
 * Se persiste en localStorage para sobrevivir la navegación dentro de la SPA
 * y poder incluirse en `/track` y en la metadata del Checkout de Stripe, aun
 * si el registro/checkout ocurre varias pantallas después del aterrizaje.
 *
 * `fbclid` NO se captura acá: el propio Meta Pixel lo lee de la URL al
 * inicializarse (`loadSnippets`) y lo persiste como cookie `_fbc` de primera
 * parte — `trackEvent.ts` ya la lee directo (`readCookie('_fbc')`), así que
 * duplicar la captura acá sería redundante.
 */
const GCLID_KEY = 'kivo7-gclid'

/** Lee un valor de localStorage sin tirar si el storage está bloqueado. */
function readStored(key: string): string | null {
  try {
    return localStorage.getItem(key)
  } catch {
    return null
  }
}

/** Guarda un click-ID en localStorage; no-op silencioso si el storage falla. */
function writeStored(key: string, value: string): void {
  try {
    localStorage.setItem(key, value)
  } catch {
    /* storage bloqueado: no persistimos, el resto del flujo sigue funcionando */
  }
}

/**
 * Lee `gclid` del query string actual y lo persiste. Llamar una vez al montar
 * la app (`main.tsx`). No pisa un click-ID ya guardado si la URL actual no
 * trae uno nuevo.
 */
export function captureClickIds(search: string): void {
  const params = new URLSearchParams(search)
  const gclid = params.get('gclid')

  if (gclid) writeStored(GCLID_KEY, gclid)
}

export function getGclid(): string | null {
  return readStored(GCLID_KEY)
}
