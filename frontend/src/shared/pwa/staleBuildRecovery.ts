/* Recuperación de build viejo ("chunk huérfano").

   Con 99 rutas lazy, una pestaña abierta durante un deploy queda pidiendo
   archivos que ya no existen. Peor: `wrangler.jsonc` usa
   `not_found_handling: "single-page-application"`, así que el servidor NO
   responde 404 — devuelve 200 con el index.html. El navegador intenta ejecutar
   HTML como si fuera JavaScript y truena con un error de MIME type.

   Verificado en producción (2026-08-05):
     GET /assets/ChargeListPage-OLDHASH1.js → 200, content-type: text/html

   La cura es siempre la misma: recargar para tomar el index.html nuevo. Acá se
   detecta ese caso concreto y se recarga UNA sola vez, con enfriamiento, para
   no entrar en bucle si la recarga no arregla nada (p. ej. el servidor está
   caído de verdad). */

const RELOAD_MARK_KEY = 'edi:stale-build-reload-at'
const RELOAD_COOLDOWN_MS = 30_000

/* Mensajes de los navegadores cuando un import dinámico no resuelve a JS.
   Se comparan en minúsculas porque el texto varía entre Chrome/Firefox/Safari. */
const STALE_BUILD_MESSAGES = [
  'failed to fetch dynamically imported module',
  'error loading dynamically imported module',
  'failed to load module script',
  'importing a module script failed',
  'expected a javascript module script',
  'unable to preload css',
]

export function isStaleBuildError(error: unknown): boolean {
  if (!(error instanceof Error)) return false
  if (error.name === 'ChunkLoadError') return true

  const message = error.message.toLowerCase()
  return STALE_BUILD_MESSAGES.some((fragment) => message.includes(fragment))
}

/* Recarga si no venimos de recargar recién. Devuelve true si disparó la recarga,
   para que quien llama sepa si todavía tiene que mostrarle algo al usuario. */
export function reloadForStaleBuild(): boolean {
  let lastReloadAt: string | null = null

  try {
    lastReloadAt = window.sessionStorage.getItem(RELOAD_MARK_KEY)
  } catch {
    /* sessionStorage bloqueado (modo privado/cookies off): sin marca no hay
       forma de frenar un bucle, así que preferimos NO recargar y dejar que el
       usuario lo haga a mano. */
    return false
  }

  if (lastReloadAt && Date.now() - Number(lastReloadAt) < RELOAD_COOLDOWN_MS) {
    return false
  }

  try {
    window.sessionStorage.setItem(RELOAD_MARK_KEY, String(Date.now()))
  } catch {
    return false
  }

  window.location.reload()
  return true
}

/* Vite emite `vite:preloadError` cuando falla la precarga de un chunk. Cubre los
   casos que el error boundary de React no ve (precargas en segundo plano,
   imports dinámicos fuera del árbol de rutas). */
export function setupStaleBuildRecovery(): void {
  window.addEventListener('vite:preloadError', (event) => {
    /* Evita que Vite re-lance el error: la recarga ya es la respuesta. */
    event.preventDefault()
    console.warn('[PWA] chunk no disponible tras un deploy, recargando', event.payload)
    reloadForStaleBuild()
  })
}
