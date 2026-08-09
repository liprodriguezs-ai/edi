/**
 * Nombres de evento CANÓNICOS del sistema de tracking (Facebook Pixel/CAPI +
 * Google GA4). Son el contrato compartido entre backend (`Modules/Tracking`),
 * el landing (`landing/src/lib/trackEvent.ts`) y esta app — NO renombrar sin
 * actualizar los tres lados.
 *
 * Facebook usa el nombre canónico directo (`fbq('track', 'Purchase', ...)`).
 * GA4 usa su propio nombre de evento (`gtag('event', 'purchase', ...)`), de
 * ahí el mapeo de abajo.
 */
export type TrackingEventName =
  | 'Lead'
  | 'StartTrial'
  | 'CompleteRegistration'
  | 'InitiateCheckout'
  | 'Purchase'

/** Nombre canónico → nombre de evento GA4. */
export const GA4_EVENT_NAME: Record<TrackingEventName, string> = {
  Lead: 'generate_lead',
  StartTrial: 'start_trial',
  CompleteRegistration: 'sign_up',
  InitiateCheckout: 'begin_checkout',
  Purchase: 'purchase',
}
