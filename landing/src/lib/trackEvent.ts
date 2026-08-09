/**
 * Helper genérico de tracking de eventos de marketing (Meta Pixel + GA4 +
 * backend). Mismo contrato que consume la app React y que despacha el
 * backend: mismo vocabulario de eventos, mismo `event_id` de deduplicación,
 * mismo endpoint `/track`. Ver FACEBOOK_TRACKING.md / GOOGLE_TRACKING.md en la
 * raíz del repo para el detalle de credenciales y verificación.
 *
 * Nunca lanza hacia el llamador: si algo falla (localStorage bloqueado, fbq/
 * gtag ausentes, fetch caído) el tracking se silencia — es best-effort y no
 * puede romper la navegación del visitante.
 */

/** Nombres canónicos de evento — el mismo vocabulario que usa el backend y la app React. */
export type TrackEventName =
  | 'Lead'
  | 'StartTrial'
  | 'CompleteRegistration'
  | 'InitiateCheckout'
  | 'Purchase';

/** Mapa nombre canónico (Facebook) → nombre recomendado de evento GA4. */
const GA4_EVENT_MAP: Record<TrackEventName, string> = {
  Lead: 'generate_lead',
  StartTrial: 'start_trial',
  CompleteRegistration: 'sign_up',
  InitiateCheckout: 'begin_checkout',
  Purchase: 'purchase',
};

/** Misma key que usa ConsentBanner.astro / BaseHead.astro para el gate de consentimiento. */
const CONSENT_KEY = 'edi-consent';

/** Misma key que persiste el script de captura de click-IDs en BaseHead.astro. */
const CLICK_IDS_KEY = 'edi-click-ids';

interface StoredClickIds {
  fbclid?: string;
  gclid?: string;
}

type FbqFn = (...args: unknown[]) => void;
type GtagFn = (...args: unknown[]) => void;

declare global {
  interface Window {
    fbq?: FbqFn;
    gtag?: GtagFn;
  }
}

function hasConsent(): boolean {
  try {
    return localStorage.getItem(CONSENT_KEY) === 'granted';
  } catch {
    return false;
  }
}

/** Lee una cookie por nombre. `_fbp`/`_fbc` las setea el propio Meta Pixel. */
function readCookie(name: string): string | undefined {
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
  return match ? decodeURIComponent(match[1]) : undefined;
}

function readStoredClickIds(): StoredClickIds {
  try {
    const raw = localStorage.getItem(CLICK_IDS_KEY);
    return raw ? (JSON.parse(raw) as StoredClickIds) : {};
  } catch {
    return {};
  }
}

const apiBase = import.meta.env.PUBLIC_API_BASE_URL ?? 'http://localhost:8080/api';

/** `crypto.randomUUID` exige Safari 15.4 — sin este fallback, un throw aquí
 *  mataría los TRES destinos (fbq, gtag y backend) en silencio. */
function generateEventId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

/**
 * Dispara un evento de marketing hacia Meta Pixel, GA4 y el backend (POST
 * `/track`), gateado por el consentimiento del visitante y deduplicado entre
 * navegador y servidor vía un `event_id` compartido. Seguro de invocar desde
 * cualquier script inline o componente — nunca lanza hacia el caller.
 */
export function trackEvent(name: TrackEventName, params: Record<string, unknown> = {}): void {
  try {
    if (!hasConsent()) return;

    const eventId = generateEventId();

    window.fbq?.('track', name, params, { eventID: eventId });
    window.gtag?.('event', GA4_EVENT_MAP[name], params);

    // El POST al backend solo corre en prod, igual que fbq/gtag arriba, para
    // no ensuciar el histórico de conversiones con tráfico de desarrollo.
    if (!import.meta.env.PROD) return;

    const { gclid } = readStoredClickIds();

    const payload = JSON.stringify({
      event: name,
      event_id: eventId,
      event_time: Math.floor(Date.now() / 1000),
      source_url: location.href,
      action_source: 'website',
      params,
      user_data: {
        fbp: readCookie('_fbp'),
        fbc: readCookie('_fbc'),
        gclid,
      },
    });

    // sendBeacon sobrevive la descarga de la página (el CTA navega a la app
    // inmediatamente después de disparar); `fetch keepalive` es poco fiable en
    // WebKit justo en ese escenario. El fetch queda como respaldo.
    const beaconSent =
      typeof navigator.sendBeacon === 'function' &&
      navigator.sendBeacon(`${apiBase}/track`, new Blob([payload], { type: 'application/json' }));

    if (!beaconSent) {
      void fetch(`${apiBase}/track`, {
        method: 'POST',
        keepalive: true,
        headers: { 'Content-Type': 'application/json' },
        body: payload,
      }).catch(() => {
        /* best-effort: una falla de red no debe afectar la navegación del CTA */
      });
    }
  } catch {
    /* best-effort: el tracking nunca debe romper el flujo del visitante */
  }
}
