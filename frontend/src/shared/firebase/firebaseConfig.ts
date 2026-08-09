import { initializeApp } from 'firebase/app'
import { getMessaging, isSupported } from 'firebase/messaging'
import { getFirebaseWebConfig, VAPID_KEY } from '@/shared/firebase/firebaseEnv'

// Config comes from env (VITE_FIREBASE_*) — never hardcoded. See firebaseEnv.ts.
const app = initializeApp(getFirebaseWebConfig())

let messagingInstance: ReturnType<typeof getMessaging> | null = null

export async function getFirebaseMessaging() {
  if (messagingInstance) return messagingInstance
  const supported = await isSupported()
  if (!supported) return null
  messagingInstance = getMessaging(app)
  return messagingInstance
}

// Re-exported so the dynamic import in useFirebaseMessaging keeps working.
export { VAPID_KEY }
