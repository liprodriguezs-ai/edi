/**
 * Single source of truth for the Firebase WEB (public) configuration.
 *
 * Everything here comes from `import.meta.env` (the `VITE_FIREBASE_*` vars) — no
 * secrets are hardcoded. These are the PUBLIC client keys that ship in the
 * browser bundle anyway; the Admin service-account JSON lives ONLY on the
 * backend (`FIREBASE_CREDENTIALS`) and must never appear here.
 *
 * The static service worker (`public/firebase-messaging-sw.js`) cannot read
 * `import.meta.env`, so we pass the same public config to it as query params on
 * the registration URL (see `buildFirebaseSwUrl`). That keeps env as the one
 * source of truth for both the app and the SW.
 */

export interface FirebaseWebConfig {
  apiKey: string
  authDomain: string
  projectId: string
  storageBucket: string
  messagingSenderId: string
  appId: string
  measurementId?: string
}

/** The public web config required to initialize Firebase in the SW (no analytics). */
const SW_CONFIG_KEYS = [
  'apiKey',
  'authDomain',
  'projectId',
  'storageBucket',
  'messagingSenderId',
  'appId',
] as const

export function getFirebaseWebConfig(): FirebaseWebConfig {
  return {
    apiKey: import.meta.env.VITE_FIREBASE_API_KEY ?? '',
    authDomain: import.meta.env.VITE_FIREBASE_AUTH_DOMAIN ?? '',
    projectId: import.meta.env.VITE_FIREBASE_PROJECT_ID ?? '',
    storageBucket: import.meta.env.VITE_FIREBASE_STORAGE_BUCKET ?? '',
    messagingSenderId: import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID ?? '',
    appId: import.meta.env.VITE_FIREBASE_APP_ID ?? '',
    measurementId: import.meta.env.VITE_FIREBASE_MEASUREMENT_ID || undefined,
  }
}

/** VAPID public key used by `getToken()` to mint web-push tokens. */
export const VAPID_KEY = (import.meta.env.VITE_FIREBASE_VAPID_KEY ?? '') as string

/**
 * Builds the service-worker registration URL with the public web config encoded
 * as query params, so the static SW can `initializeApp` from a single source.
 * Only the PUBLIC web keys are forwarded — never the VAPID key or any secret.
 */
export function buildFirebaseSwUrl(swPath: string, config: FirebaseWebConfig): string {
  const params = new URLSearchParams()
  for (const key of SW_CONFIG_KEYS) {
    params.set(key, config[key])
  }
  return `${swPath}?${params.toString()}`
}
