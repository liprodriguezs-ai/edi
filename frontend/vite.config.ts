import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import path from 'path'
import { defineConfig, type PluginOption } from 'vite'
import viteCompression from 'vite-plugin-compression'
import { VitePWA } from 'vite-plugin-pwa'

import { cloudflare } from '@cloudflare/vite-plugin'

export default defineConfig(({ mode }) => {
  // vite-plugin-react-inspector deshabilitado: su inject usa ReactDOM.render
  // (eliminado en React 19) y truena la consola en cada página de dev.
  const plugins: PluginOption[] = [
    react(),
    tailwindcss(),
    cloudflare(),
    // PWA = SOLO instalable (sin cacheo offline de datos de API). Convive con el
    // service worker de Firebase (public/firebase-messaging-sw.js):
    //  - El SW de la PWA (workbox) controla el scope '/'.
    //  - El SW de FCM se registra bajo '/firebase-cloud-messaging-push-scope'
    //    (ver useFirebaseMessaging.ts), así NO se pisan.
    //  - Sin runtime caching de '/api' → los datos siempre van a la red.
    VitePWA({
      /* `autoUpdate` (NO cambiar a 'prompt' sin leer esto) — workbox mete
         skipWaiting + clientsClaim DENTRO del sw.js, así que el SW nuevo se
         activa SOLO, sin depender de que la página se lo pida.

         Con 'prompt' el único skipWaiting del SW vive detrás de un mensaje
         `SKIP_WAITING` que manda la app. Si el bundle cargado no sabe mandarlo
         (versión vieja, o el bug del 2026-06-28→07-24 donde nadie consumía
         `virtual:pwa-register`), el SW nuevo queda en waiting PARA SIEMPRE y el
         usuario sólo se destraba borrando caché a mano. Deadlock: para correr el
         código que arregla el bug primero hay que salir del bug.

         La UX de "avisar antes de recargar" NO se pierde: se conserva vía
         `onNeedReload` en setupPwaUpdatePrompt.ts. */
      registerType: 'autoUpdate',
      injectRegister: 'auto',
      // Iconos/estáticos de public/ que el SW precachea como app-shell.
      includeAssets: [
        'favicon.ico',
        'favicon.svg',
        'favicon.png',
        'icons/edi-mark.svg',
        'icons/icon-192.png',
        'icons/icon-512.png',
        'icons/icon-maskable-512.png',
        'icons/apple-touch-icon.png',
      ],
      manifest: {
        name: 'Prueba EDI Digital',
        short_name: 'Prueba EDI',
        description: 'Evaluación del desarrollo infantil',
        lang: 'es',
        display: 'standalone',
        start_url: '/',
        scope: '/',
        theme_color: '#078e88',
        background_color: '#ffffff',
        icons: [
          { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png', purpose: 'any' },
          { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any' },
          {
            src: '/icons/icon-maskable-512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'maskable',
          },
        ],
      },
      workbox: {
        // App-shell SPA: navegaciones caen a index.html, EXCEPTO /api y los SW de FCM.
        navigateFallback: '/index.html',
        navigateFallbackDenylist: [
          /^\/api/,
          /^\/firebase-messaging-sw\.js$/,
          /^\/firebase-cloud-messaging-push-scope/,
        ],
        // NO precachear el SW de Firebase (se sirve tal cual desde public/).
        globIgnores: ['**/firebase-messaging-sw.js'],
        cleanupOutdatedCaches: true,
        // SIN runtimeCaching → cero cacheo de datos de API (network-only).
      },
      // El SW solo se genera/registra en build; el dev server queda limpio.
      devOptions: { enabled: false },
    }),
  ]

  if (mode === 'production') {
    plugins.push(
      viteCompression({
        algorithm: 'gzip',
        ext: '.gz',
        threshold: 1024,
        deleteOriginFile: false,
        verbose: false,
      })
    )
  }

  return {
    plugins,
    build: {
      rollupOptions: {
        output: {
          // Aísla vendors pesados en chunks propios para que no inflen el
          // bundle inicial. Firebase ya sale por import dinámico; dnd-kit y
          // react-select se separan acá para cachearse aparte. Rolldown (Vite 8)
          // exige la forma de función para manualChunks.
          manualChunks(id: string) {
            if (id.includes('@dnd-kit')) return 'dnd'
            if (id.includes('react-select')) return 'react-select'
            return undefined
          },
        },
      },
    },
    server: {
      // `host: true` expone el dev server en la red (0.0.0.0) para poder abrir
      // la app desde otro dispositivo de la LAN (http://<IP>:5173). NO tiene
      // relación con el fix de auth (eso es del lado backend: la cookie de
      // refresh es Secure y una IP por HTTP no es contexto seguro).
      host: true,
      // 5190, NO 5189: el bloque de puertos de este proyecto es la familia "90"
      // (APP_PORT=8090, VITE_PORT=5290, FORWARD_DB_PORT=5450). El 5189 pertenece
      // a otro proyecto y estaba acá sólo porque este vite.config se copió de ese
      // repo sin actualizarle los puertos — con ambos proyectos arriba, el que
      // arrancaba segundo se quedaba sin su puerto.
      port: 5190,
      // `strictPort` es OBLIGATORIO, no una preferencia. Con el default (`false`)
      // Vite se corre solo al siguiente puerto libre si el suyo está ocupado, pero
      // `start.sh` ya escribió `SANCTUM_STATEFUL_DOMAINS` leyendo ESTE puerto: si el
      // dev server termina en otro, el origen deja de matchear, la cookie httpOnly
      // de refresh no viaja y la sesión se cae a /login sin un solo mensaje de error.
      strictPort: true,
      proxy: {
        '/api': {
          // 8090 = APP_PORT de ESTE proyecto. Antes apuntaba a 8089, que es la API
          // de otro proyecto — otro residuo de la misma copia.
          target: 'http://127.0.0.1:8090',
          changeOrigin: true,
        },
      },
    },
    resolve: {
      alias: {
        '@': path.resolve(__dirname, './src'),
      },
    },
  }
})
