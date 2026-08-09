/**
 * Module-scope runtime for Firebase Cloud Messaging.
 *
 * The token registration and the `onMessage` listener are singletons that must
 * survive re-renders, so they live here rather than in component state. They
 * MUST be torn down on logout (see `resetFcmGlobals`): otherwise, after an
 * in-SPA logout+login WITHOUT a full page reload, user A's `onMessage` listener
 * stays alive for user B and the device token is never re-registered.
 *
 * `resetFcmGlobals` is called from `sessionStore.logout` — the single choke point
 * every logout funnels through (including the 401 interceptor's in-SPA
 * `logout({ propagate: false })`). For the full-reload path it is a harmless
 * no-op (the JS context is discarded anyway).
 *
 * This file is a LEAF (no heavy imports) so `sessionStore` can import it without
 * creating a cycle (apiClient → sessionStore → … → apiClient).
 */
interface FcmRuntime {
  /** In-flight (or settled) token-registration promise; dedupes concurrent inits. */
  initPromise: Promise<boolean> | null
  /** Whether the singleton onMessage listener has been attached. */
  listenerSet: boolean
  /** Teardown returned by onMessage(); call to detach the foreground listener. */
  unsubscribe: (() => void) | null
  /** Latest router navigate fn, used by the singleton listener (set by the hook). */
  navigate: ((_to: string) => void) | null
}

export const fcmRuntime: FcmRuntime = {
  initPromise: null,
  listenerSet: false,
  unsubscribe: null,
  navigate: null,
}

/** Detaches the foreground listener and clears all FCM runtime state (call on logout). */
export function resetFcmGlobals(): void {
  if (fcmRuntime.unsubscribe) {
    try {
      fcmRuntime.unsubscribe()
    } catch {
      // Best-effort teardown — ignore SDK errors during unsubscribe.
    }
  }
  fcmRuntime.initPromise = null
  fcmRuntime.listenerSet = false
  fcmRuntime.unsubscribe = null
  fcmRuntime.navigate = null
}
