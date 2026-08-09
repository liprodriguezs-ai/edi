/**
 * Gate de consentimiento para analytics en la app autenticada.
 *
 * Reusa la MISMA key que el `ConsentBanner` del landing (`kivo7-consent`), así
 * si el visitante ya decidió ahí (o decide después dentro de la app, cuando
 * exista un banner propio), la elección se respeta en ambos lados sin
 * duplicar el flag.
 *
 * La app hoy NO tiene banner de cookies propio: mientras tanto, sin
 * consentimiento explícito (`'granted'`) el analytics queda DENEGADO por
 * default. El tracking sobre usuarios ya autenticados debe quedar cubierto
 * por el Aviso de Privacidad — pendiente de validación legal antes de asumir
 * consentimiento implícito. Este helper es intencionalmente simple para que
 * un banner real pueda enchufarse después sin tocar los llamadores.
 */
const CONSENT_KEY = 'kivo7-consent'

export function hasAnalyticsConsent(): boolean {
  try {
    return localStorage.getItem(CONSENT_KEY) === 'granted'
  } catch {
    // localStorage bloqueado (modo privado, políticas del navegador, etc.):
    // sin forma de confirmar consentimiento, tratamos como denegado.
    return false
  }
}

/**
 * Otorga consentimiento de analytics de forma IMPLÍCITA al registrarse o
 * iniciar sesión — puente mientras la app no tiene banner de cookies propio,
 * tratando la aceptación del Aviso de Privacidad en el signup/login (que ya
 * menciona el uso de analytics) como el opt-in.
 *
 * PENDIENTE: confirmación legal explícita de que esto cubre el consentimiento
 * de analytics bajo la LFPDPPP (México) — es defendible, pero no reemplaza un
 * banner/checkbox dedicado si el equipo legal lo exige más adelante.
 */
export function grantAnalyticsConsent(): void {
  try {
    localStorage.setItem(CONSENT_KEY, 'granted')
  } catch {
    // storage bloqueado: el usuario simplemente no obtiene tracking en este navegador
  }
}
