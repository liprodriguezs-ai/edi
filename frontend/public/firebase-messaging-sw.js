/* global importScripts, firebase, clients */
// The compat SDK is PINNED to 10.12.0 on purpose: this static service worker
// can't import the bundled `firebase` npm package, so it loads the compat build
// from the CDN. Keep this version in sync when bumping `firebase` in
// package.json (the app SDK and this SW should track the same major).
importScripts('https://www.gstatic.com/firebasejs/10.12.0/firebase-app-compat.js')
importScripts('https://www.gstatic.com/firebasejs/10.12.0/firebase-messaging-compat.js')

// The SW can't read import.meta.env, so the app passes the PUBLIC web config as
// query params on the registration URL (see firebaseEnv.ts -> buildFirebaseSwUrl).
// Single source of truth = the VITE_FIREBASE_* env vars. Only public web keys are
// forwarded here — never the VAPID key or the Admin service account.
const params = new URL(self.location).searchParams

firebase.initializeApp({
  apiKey: params.get('apiKey'),
  authDomain: params.get('authDomain'),
  projectId: params.get('projectId'),
  storageBucket: params.get('storageBucket'),
  messagingSenderId: params.get('messagingSenderId'),
  appId: params.get('appId'),
})

const messaging = firebase.messaging()

messaging.onBackgroundMessage((payload) => {
  const title = payload.notification?.title ?? 'Nueva notificación'
  const options = {
    body: payload.notification?.body ?? '',
    icon: '/icons/icon-192.png',
    badge: '/icons/icon-192.png',
    data: payload.data,
  }
  self.registration.showNotification(title, options)
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = event.notification.data?.click_action ?? '/'
  event.waitUntil(clients.openWindow(url))
})
