import { hasAnalyticsConsent } from '@/shared/analytics/consent'

/**
 * Stub de `fbq` compatible con el contrato de fbevents.js: mientras el script
 * real no cargó, encola las llamadas (`queue`); al cargar, fbevents.js
 * localiza `window.fbq` (esta misma función) y le agrega `callMethod`, momento
 * en que las llamadas empiezan a despacharse directo.
 */
interface FbqQueueFn {
  (...args: unknown[]): void
  callMethod?: (...args: unknown[]) => void
  queue: unknown[][]
  push: FbqQueueFn
  loaded: boolean
  version: string
}

/**
 * Tipado ambiental de los globals que inyectan gtag.js y fbevents.js. Se
 * declara acá porque este módulo es el que efectivamente los inyecta; el
 * resto de `shared/analytics` los consume vía `window.gtag`/`window.fbq`.
 */
declare global {
  interface Window {
    dataLayer?: unknown[]
    gtag?: (...args: unknown[]) => void
    fbq?: FbqQueueFn
    _fbq?: FbqQueueFn
  }
}

// Mismos IDs de producción que usa el landing (`BaseHead.astro`) — overrideables
// por env para poder apuntar a otra cuenta en staging sin tocar código.
const GA_ID = (import.meta.env.VITE_GA_ID as string | undefined) || 'G-X9EFEZRG28'
const META_PIXEL_ID = (import.meta.env.VITE_META_PIXEL_ID as string | undefined) || '1012668624876358'

let injected = false

function loadGoogleAnalytics(): void {
  if (!GA_ID) return

  window.dataLayer = window.dataLayer || []
  window.gtag = window.gtag ?? function gtag(...args: unknown[]) {
    window.dataLayer?.push(args)
  }
  window.gtag('js', new Date())
  window.gtag('config', GA_ID)

  const script = document.createElement('script')
  script.async = true
  script.src = `https://www.googletagmanager.com/gtag/js?id=${GA_ID}`
  document.head.appendChild(script)
}

function createFbqStub(): FbqQueueFn {
  const fbq = ((...args: unknown[]) => {
    if (fbq.callMethod) {
      fbq.callMethod(...args)
    } else {
      fbq.queue.push(args)
    }
  }) as FbqQueueFn
  fbq.queue = []
  fbq.push = fbq
  fbq.loaded = true
  fbq.version = '2.0'
  return fbq
}

function loadMetaPixel(): void {
  if (!META_PIXEL_ID) return

  if (!window.fbq) {
    window.fbq = createFbqStub()
    window._fbq = window.fbq

    const script = document.createElement('script')
    script.async = true
    script.src = 'https://connect.facebook.net/en_US/fbevents.js'
    document.head.appendChild(script)
  }

  // Ya se validó consentimiento antes de llegar acá (ver `loadSnippets`), pero
  // seguimos la secuencia revoke→init→grant del landing (`BaseHead.astro`) por
  // si el bootstrap se re-ejecuta o el script tarda en cargar.
  window.fbq('consent', 'revoke')
  window.fbq('init', META_PIXEL_ID)
  window.fbq('consent', 'grant')
  window.fbq('track', 'PageView')
}

/**
 * Inyecta gtag.js + fbevents.js en la app autenticada. SOLO en producción
 * (`import.meta.env.PROD`) y SOLO si hay consentimiento de analytics — nunca
 * en dev, nunca sin opt-in. Idempotente: llamarla más de una vez no duplica
 * los `<script>`.
 */
export function loadSnippets(): void {
  if (injected) return
  if (!import.meta.env.PROD) return
  if (!hasAnalyticsConsent()) return

  injected = true
  loadGoogleAnalytics()
  loadMetaPixel()
}
