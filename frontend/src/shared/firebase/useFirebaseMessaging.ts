import { useCallback, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { apiClient } from '@/shared/api/apiClient'
import { queryClient } from '@/shared/api/queryClient'
import { fcmRuntime } from '@/shared/firebase/fcmRuntime'
import { toast } from '@/shared/hooks/useToast'
import {
  playNotificationSound,
  unlockAudioOnFirstGesture,
} from '@/shared/notifications/notificationSound'
import { incrementUnread, RECENT_KEY, UNREAD_COUNT_KEY } from '@/shared/notifications/unreadCounter'

// El SDK de Firebase se importa DINÁMICAMENTE para que NO entre al bundle
// inicial — solo se descarga cuando este hook inicializa (tras montar el
// dashboard, en navegadores con soporte de push).
const loadFirebase = () => import('@/shared/firebase/firebaseConfig')
const loadMessaging = () => import('firebase/messaging')

// El listener de onMessage es un singleton global (fcmRuntime), así que NO puede
// llamar a useNavigate() directamente. Usamos la ref al navigate del router (la
// actualiza el hook) y caemos a window.location como último recurso, para que el
// click del toast SIEMPRE navegue bien.
function navigateTo(to: string): void {
  if (fcmRuntime.navigate) {
    fcmRuntime.navigate(to)
    return
  }
  window.location.assign(to)
}

async function registerAndSendToken(): Promise<boolean> {
  try {
    const { getFirebaseMessaging, VAPID_KEY } = await loadFirebase()
    const { getToken } = await loadMessaging()

    const messaging = await getFirebaseMessaging()
    if (!messaging) {
      return false
    }

    let permission = Notification.permission
    if (permission === 'default') {
      permission = await Notification.requestPermission()
    }
    if (permission !== 'granted') {
      return false
    }

    // Pass the PUBLIC web config to the static SW via query params (it can't read
    // import.meta.env). Register under the conventional FCM scope so it coexists
    // with the PWA service worker that controls '/'.
    const { buildFirebaseSwUrl, getFirebaseWebConfig } = await import('@/shared/firebase/firebaseEnv')
    const swUrl = buildFirebaseSwUrl('/firebase-messaging-sw.js', getFirebaseWebConfig())
    const registration = await navigator.serviceWorker.register(swUrl, {
      scope: '/firebase-cloud-messaging-push-scope',
    })

    let token: string | null = null
    try {
      token = await getToken(messaging, {
        vapidKey: VAPID_KEY,
        serviceWorkerRegistration: registration,
      })
    } catch (tokenError) {
      // "push service error" ocurre en algunos browsers sin HTTPS (Edge, Firefox en localhost)
      console.warn('[FCM] getToken falló (probablemente sin HTTPS):', tokenError)
      return false
    }

    if (!token) {
      return false
    }

    await apiClient.patch('/user/setTokenSender', { token_sender: token })

    return true
  } catch (error) {
    console.error('[FCM] Error en registro:', error)
    return false
  }
}

/**
 * Inicializa Firebase Cloud Messaging:
 * - Registra service worker y obtiene token en cada mount (login/recarga)
 * - Si el token cambia (Firebase los rota), lo actualiza en el backend
 * - Si el usuario no dio permiso, reintenta cuando cambia Notification.permission
 * - Escucha mensajes foreground → toast + refresh de datos
 *
 * Su estado global (init/listener/navigate/unsubscribe) vive en fcmRuntime y se
 * resetea en logout (resetFcmGlobals) para no dejar vivo el listener del usuario
 * anterior tras un logout+login in-SPA.
 */
export function useFirebaseMessaging() {
  const navigate = useNavigate()

  // Mantené la ref de navigate al día para que el listener singleton navegue por
  // el router (sin recarga). También desbloqueá el audio en el primer gesto.
  useEffect(() => {
    fcmRuntime.navigate = navigate
    unlockAudioOnFirstGesture()
    return () => {
      if (fcmRuntime.navigate === navigate) {
        fcmRuntime.navigate = null
      }
    }
  }, [navigate])

  const init = useCallback(async () => {
    if (!fcmRuntime.initPromise) {
      fcmRuntime.initPromise = registerAndSendToken()
    }
    const success = await fcmRuntime.initPromise

    if (success && !fcmRuntime.listenerSet) {
      fcmRuntime.listenerSet = true
      const { getFirebaseMessaging } = await loadFirebase()
      const { onMessage } = await loadMessaging()
      const messaging = await getFirebaseMessaging()
      if (messaging) {
        // Guardamos el unsubscribe para poder desmontar el listener en logout.
        fcmRuntime.unsubscribe = onMessage(messaging, (payload) => {
          const title = payload.notification?.title ?? 'Nueva notificación'
          const body = payload.notification?.body ?? ''
          const clickAction = payload.data?.click_action
          const canNavigate = typeof clickAction === 'string' && clickAction.startsWith('/')

          playNotificationSound()
          toast.info({
            title,
            description: body,
            position: 'top-right',
            ...(canNavigate
              ? { button: { title: 'Ver', onClick: () => navigateTo(clickAction) } }
              : {}),
          })

          // Mantenimiento incremental del badge (+1) + refresco de la lista del dropdown.
          queryClient.setQueryData(UNREAD_COUNT_KEY, incrementUnread)
          void queryClient.invalidateQueries({ queryKey: RECENT_KEY })
        })
      } else {
        // No se pudo crear el listener; permití reintentar en el próximo init.
        fcmRuntime.listenerSet = false
      }
    }
  }, [])

  // Ejecutar al montar (login o recarga de página)
  useEffect(() => {
    void init()
  }, [init])

  // Escuchar cambios de permiso — si el usuario da permiso después, registrar token
  useEffect(() => {
    if (!('permissions' in navigator)) return

    let cleanup: (() => void) | undefined

    navigator.permissions.query({ name: 'notifications' as PermissionName }).then((status) => {
      const handler = () => {
        if (status.state !== 'granted') return

        if (!fcmRuntime.listenerSet) {
          // Permission granted AFTER mount: the initial init() settled initPromise to
          // false (no permission then), so the onMessage foreground listener never
          // attached — only background SW push worked. Reset the cached promise and
          // re-init so the token registers AND the listener (toast + sound + badge)
          // hooks up without requiring a page reload.
          fcmRuntime.initPromise = null
          void init()
        } else {
          // Listener already attached → just refresh the (possibly rotated) token.
          void registerAndSendToken()
        }
      }
      status.addEventListener('change', handler)
      cleanup = () => status.removeEventListener('change', handler)
    }).catch(() => { /* browser no soporta permissions API */ })

    return () => cleanup?.()
  }, [init])
}
