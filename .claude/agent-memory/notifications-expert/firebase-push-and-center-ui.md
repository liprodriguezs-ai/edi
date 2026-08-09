# Firebase/push integration (Cambio 1) + Notification center UI (Cambio 2) — 2026-06-27

## Firebase / push — INTEGRATED to env + PWA

- Backend config shape unchanged: `config/services.php` → `firebase.credentials = env('FIREBASE_CREDENTIALS')`.
  SDK `kreait/firebase-php`. Token: `user.token_sender` (single column, central `user` table — NO
  device-token table; app updates via `PATCH /user/setTokenSender`).
- `FirebasePushChannel::send()` does FCM `sendMulticast` SYNCHRONOUSLY (dedups tokens; self-skips with
  warning when credentials absent). Async+retry come from `DeliverChannelMessageJob`; old
  `SendPushNotificationJob` DELETED.
- **Project**: `kivo7-475fa` (service account at repo root `firebase-auth.json`, now gitignored). Brand = Kivo7.
- `config/notifications.php` default `'log'` → `'log,firebase_push'`; **phpunit.xml PINS
  `NOTIFICATION_CHANNELS=log`** — channel-dependent tests override via `config(['notifications.channels'=>...])`.
- Frontend env-driven (`frontend/src/shared/firebase/firebaseEnv.ts`): `getFirebaseWebConfig()` reads
  `VITE_FIREBASE_{API_KEY,AUTH_DOMAIN,PROJECT_ID,STORAGE_BUCKET,MESSAGING_SENDER_ID,APP_ID,MEASUREMENT_ID}`;
  `VAPID_KEY = VITE_FIREBASE_VAPID_KEY`. Static SW can't read import.meta.env → `useFirebaseMessaging.ts`
  registers `buildFirebaseSwUrl('/firebase-messaging-sw.js', config)` (public keys as query params) under
  scope `'/firebase-cloud-messaging-push-scope'`; SW reads `new URL(self.location).searchParams`.
- **PWA** via `vite-plugin-pwa` (generateSW, `registerType:'prompt'`), manifest "Kivo7", placeholder icons.
  Coexistence: PWA SW controls `/`; FCM SW under its scope; `navigateFallbackDenylist` excludes `/api` +
  FCM SW; `globIgnores` skips precaching it; NO runtime caching of `/api`.
- Backend `.env` `FIREBASE_CREDENTIALS` had pointed at a STALE path in another repo — fixed to
  `/Users/rodriguez/code/residia/firebase-auth.json`.
- STILL TO VERIFY (real device): `VITE_FIREBASE_*` vars in frontend `.env*` (none present at change time);
  app captures `token_sender`; push with real token + FCM dashboard.

## Notification center UI / unread badge

- `notification_user/unread-count` endpoint REMOVED. Initial badge = `POST /notification_user/search?limit=1`
  with `is_read=false` filter → read **`meta.total`**. Maintained incrementally in RQ cache via pure helpers
  in `frontend/src/shared/notifications/unreadCounter.ts` (`incrementUnread`/`decrementUnread`/`resetUnread`;
  keys `['notifications','unread']`, `['notifications','recent']`). NO `refetchInterval`;
  `refetchOnWindowFocus:true` reconciles drift.
- Rich in-app toast (`useFirebaseMessaging` `onMessage`): sileo `toast.info({title,description,position,button})`,
  button "Ver" shown only when `payload.data.click_action` starts with `/`. Navigation from the singleton
  listener: `navigateTo()` reads `fcmRuntime.navigate` (set by hook), falls back to `window.location.assign`.
- Sound: `frontend/src/shared/notifications/notificationSound.ts` plays `/sounds/notification.wav`,
  AudioContext beep fallback (singleton, recreated only if closed). `unlockAudioOnFirstGesture()` primes on
  first click/keydown. Mute = localStorage `notif_sound_muted` + `SoundPreferenceCard` Switch in
  `NotificationPreferencesPage`. No backend.
- Review fixes: FCM singleton state in LEAF module `frontend/src/shared/firebase/fcmRuntime.ts`
  (`resetFcmGlobals()` called from `sessionStore.logout` — single choke point, no import cycle). Bell data
  layer via `NotificationUserResource` (`createCrudApi`) at
  `frontend/src/modules/notification/services/NotificationUserResource.ts` (`searchUnread`/`searchRecent`/
  `markAllRead`/`markRead`); `INotificationUser` from `modules/notification/schema.ts`. a11y: `scope="col"`,
  htmlFor/id on quiet-hours inputs, sr-only "(sin leer)", removed `user-scalable=no` from viewport.
