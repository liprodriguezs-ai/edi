import { toast } from '@/shared/hooks/useToast'
import { registerSW } from 'virtual:pwa-register'

/* El SW se genera con `registerType: 'autoUpdate'` (vite.config.ts): el propio
   sw.js hace skipWaiting + clientsClaim, así que una versión nueva SIEMPRE se
   activa aunque la página cargada sea vieja. Eso es lo que rescata a un usuario
   atrapado en un bundle viejo — no depende de que su código sepa pedirlo.

   Lo que este módulo controla es CUÁNDO recarga la pestaña. Por defecto, con
   autoUpdate el plugin recarga solo apenas el SW activa; pasando `onNeedReload`
   tomamos esa decisión nosotros y mantenemos la política acordada:

   1. El usuario YA ESTÁ TRABAJANDO (tocó la pantalla o el teclado) → toast
      persistente "Nueva versión disponible" con botón Actualizar. Si lo ignora
      no se le interrumpe nada.
   2. El usuario NO TOCÓ NADA todavía → recarga sola, sin preguntar: no hay
      trabajo que perder.

   El criterio es la interacción, NO un temporizador desde el arranque. Medido
   con Playwright (2026-08-05): instalar el precache (300 entradas, 4.2 MB) tarda
   MÁS de 3 s, así que una ventana de gracia por tiempo se vencía siempre y el
   caso 2 no ocurría nunca en la práctica.

   OJO: si el usuario ignora el toast, su pestaña queda con código viejo pero SW
   nuevo, y los chunks lazy viejos ya no existen (ni en caché ni en el servidor).
   La red de seguridad para ese hueco es staleBuildRecovery.ts. */

/* El navegador solo re-chequea el sw.js en navegaciones duras o cada 24 h; en
   una SPA que vive abierta eso casi nunca pasa. Este intervalo pide el update
   check (un GET a sw.js, costo despreciable) para que el aviso aparezca el
   mismo día del deploy. */
const UPDATE_CHECK_INTERVAL_MS = 60 * 60 * 1000

export function setupPwaUpdatePrompt(): void {
  let userIsWorking = false
  const markUserIsWorking = () => {
    userIsWorking = true
  }

  window.addEventListener('pointerdown', markUserIsWorking, { once: true, passive: true })
  window.addEventListener('keydown', markUserIsWorking, { once: true, passive: true })

  registerSW({
    onRegisteredSW(_swUrl, registration) {
      if (!registration) return
      window.setInterval(() => void registration.update(), UPDATE_CHECK_INTERVAL_MS)
    },
    /* En modo autoUpdate el SW YA se activó cuando esto corre: acá sólo se
       decide el momento de la recarga (`updateServiceWorker()` es no-op). */
    onNeedReload() {
      if (!userIsWorking) {
        window.location.reload()
        return
      }

      toast.action({
        title: 'Nueva versión disponible',
        description:
          'Actualiza para ver las últimas mejoras. Si lo dejas para después, se aplicará sola la próxima vez que abras la app.',
        duration: null,
        button: {
          title: 'Actualizar',
          onClick: () => window.location.reload(),
        },
      })
    },
  })
}
