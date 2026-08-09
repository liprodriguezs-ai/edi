import { randomId } from '@/shared/utils/randomId'
import { getGclid } from '@/shared/analytics/clickIds'
import { hasAnalyticsConsent } from '@/shared/analytics/consent'
import { GA4_EVENT_NAME, type TrackingEventName } from '@/shared/analytics/eventNames'
import { apiClient } from '@/shared/api/apiClient'

export interface TrackEventUserData {
  email?: string
  external_id?: string
  fbp?: string
  fbc?: string
  gclid?: string
}

export type TrackEventParams = Record<string, string | number | boolean | undefined>

/** Lee una cookie por nombre; devuelve undefined si no existe (SSR-safe no aplica, siempre hay `document` en esta app). */
function readCookie(name: string): string | undefined {
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`))
  return match ? decodeURIComponent(match[1]) : undefined
}

/**
 * Client ID de GA (cookie `_ga`, formato `GA1.2.<clientId>`). Lectura
 * best-effort por cookie para no depender del callback async de
 * `gtag('get', ...)`; si la cookie no existe (sin consentimiento, gtag no
 * cargó, adblock) devuelve undefined y el checkout simplemente no la manda.
 */
export function getGaClientId(): string | undefined {
  const raw = readCookie('_ga')
  if (!raw) return undefined
  const parts = raw.split('.')
  return parts.length >= 4 ? parts.slice(-2).join('.') : undefined
}

const PURCHASE_INTENT_KEY = 'edi-purchase-intent'
const PURCHASE_TRACKED_KEY = 'edi-purchase-tracked'

export interface PurchaseIntent {
  eventId: string
  value?: number
  currency?: string
}

/**
 * Persiste el `event_id` (+ monto) generado al disparar InitiateCheckout, para
 * reusarlo en el Purchase del navegador cuando el usuario vuelve de Stripe.
 * Así el Purchase del navegador y el del webhook (que recibe este mismo
 * `event_id` vía metadata del Checkout Session) comparten identidad y Meta
 * deduplica en vez de contar la conversión dos veces.
 */
export function persistPurchaseIntent(intent: PurchaseIntent): void {
  try {
    localStorage.setItem(PURCHASE_INTENT_KEY, JSON.stringify(intent))
  } catch {
    /* storage bloqueado: el Purchase de vuelta generará su propio event_id */
  }
}

/**
 * Lee el intent persistido SIN borrarlo — un refresh de `?success=true` vuelve
 * a montar `SuccessView` y necesita poder leerlo de nuevo para decidir si YA
 * se trackeó (ver `isPurchaseIntentTracked`), no perderlo en el primer read.
 */
export function readPurchaseIntent(): PurchaseIntent | null {
  try {
    const raw = localStorage.getItem(PURCHASE_INTENT_KEY)
    return raw ? (JSON.parse(raw) as PurchaseIntent) : null
  } catch {
    return null
  }
}

/** Marca un `event_id` de Purchase como ya trackeado (sobrevive refresh/remount). */
export function markPurchaseIntentTracked(eventId: string): void {
  try {
    localStorage.setItem(PURCHASE_TRACKED_KEY, eventId)
  } catch {
    /* storage bloqueado: en el peor caso un refresh puede re-disparar el Purchase */
  }
}

/** true si ESTE `event_id` de Purchase ya fue trackeado en un mount anterior. */
export function isPurchaseIntentTracked(eventId: string): boolean {
  try {
    return localStorage.getItem(PURCHASE_TRACKED_KEY) === eventId
  } catch {
    return false
  }
}

/**
 * Dispara un evento de marketing hacia Facebook (Pixel + CAPI) y Google
 * (gtag). Genera un `event_id` (o reusa uno dado en `explicitEventId` — ej. el
 * Purchase que vuelve de Stripe reusando el id generado en InitiateCheckout) y
 * lo usa tanto en `fbq(..., {eventID})` como en el POST a `/track`, para que
 * Meta deduplique navegador vs servidor.
 *
 * SIN consentimiento (`hasAnalyticsConsent()` false) no dispara NADA — ni
 * pixels ni el POST a `/track` — para no filtrar PII sin opt-in. Igual
 * devuelve un `event_id` válido (nunca transmitido) para que llamadores como
 * el checkout, que lo hilan en la metadata de Stripe independientemente del
 * consentimiento de analytics, no se rompan.
 *
 * Nunca lanza hacia el llamador: un fallo de tracking (red, adblock, backend
 * caído) no debe romper el flujo de negocio que lo dispara (registro,
 * checkout, etc.).
 */
export async function trackEvent(
  name: TrackingEventName,
  params: TrackEventParams = {},
  userData?: TrackEventUserData,
  explicitEventId?: string,
): Promise<string> {
  const eventId = explicitEventId ?? randomId()

  if (!hasAnalyticsConsent()) {
    return eventId
  }

  window.fbq?.('track', name, params, { eventID: eventId })
  window.gtag?.('event', GA4_EVENT_NAME[name], params)

  const resolvedUserData: TrackEventUserData = {
    ...userData,
    fbp: userData?.fbp ?? readCookie('_fbp'),
    fbc: userData?.fbc ?? readCookie('_fbc'),
    gclid: userData?.gclid ?? getGclid() ?? undefined,
  }

  try {
    await apiClient.post('/track', {
      event: name,
      event_id: eventId,
      event_time: Math.floor(Date.now() / 1000),
      source_url: window.location.href,
      action_source: 'website',
      params,
      user_data: resolvedUserData,
    })
  } catch (error) {
    console.error('[tracking] no se pudo enviar el evento a /track', name, error)
  }

  return eventId
}
