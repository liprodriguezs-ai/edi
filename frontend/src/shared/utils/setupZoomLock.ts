/**
 * Bloquea el zoom por pellizco (pinch) para que la app instalada se sienta
 * nativa y no una página web dentro de un marco.
 *
 * POR QUÉ HACE FALTA ESTO SI YA ESTÁ EL META VIEWPORT:
 * Safari en iOS IGNORA a propósito `user-scalable=no` / `maximum-scale` desde
 * iOS 10 (decisión de accesibilidad de Apple). El único gancho que WebKit sí
 * respeta son sus eventos propietarios de gesto — `gesturestart`,
 * `gesturechange` y `gestureend` — que se disparan al juntar dos dedos. Al
 * cancelarlos, iOS no escala la página. En Android/Chrome el bloqueo ya lo
 * resuelve el meta viewport, así que esto es puramente la capa de iOS.
 *
 * ESCAPE DELIBERADO: cualquier elemento (o ancestro) marcado con
 * `data-allow-zoom` deja pasar el gesto. Se usa en los QR de visita y de
 * paquetería, donde acercar es justamente lo que el residente quiere hacer.
 */

const ZOOM_ALLOWED_SELECTOR = '[data-allow-zoom]'

const isInsideZoomAllowedArea = (target: EventTarget | null): boolean => {
  if (!(target instanceof Element)) return false
  return target.closest(ZOOM_ALLOWED_SELECTOR) !== null
}

const blockGesture = (event: Event): void => {
  if (isInsideZoomAllowedArea(event.target)) return
  event.preventDefault()
}

export const setupZoomLock = (): void => {
  // Eventos WebKit-only: en navegadores que no los implementan simplemente
  // nunca se disparan, así que registrarlos es inocuo.
  const gestureEvents = ['gesturestart', 'gesturechange', 'gestureend']

  gestureEvents.forEach((eventName) => {
    document.addEventListener(eventName, blockGesture, { passive: false })
  })
}
