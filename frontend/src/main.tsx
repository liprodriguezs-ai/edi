import App from '@/App.tsx'
import '@/index.css'
import { captureClickIds } from '@/shared/analytics/clickIds'
import { loadSnippets } from '@/shared/analytics/loadSnippets'
import { queryClient } from '@/shared/api/queryClient'
import { SessionProvider } from '@/shared/auth/SessionProvider'
import { PermissionsModal } from '@/shared/components/PermissionsModal'
import { setupPwaUpdatePrompt } from '@/shared/pwa/setupPwaUpdatePrompt'
import { setupStaleBuildRecovery } from '@/shared/pwa/staleBuildRecovery'
import { setupZoomLock } from '@/shared/utils/setupZoomLock'
import { QueryClientProvider } from '@tanstack/react-query'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { Toaster } from 'sileo'
import 'sileo/styles.css'

// Tracking: captura gclid/fbclid reenviados por el landing y carga gtag/fbevents
// (gateado a prod + consentimiento, no-bloqueante — ver shared/analytics).
captureClickIds(window.location.search)
loadSnippets()

// PWA: el SW se auto-actualiza (registerType autoUpdate); esto sólo decide
// cuándo recargar la pestaña — aviso a mitad de sesión, recarga sola al arrancar.
// En dev es no-op (devOptions.enabled=false → el virtual module no registra nada).
setupPwaUpdatePrompt()

// Red de seguridad: si la pestaña quedó en un build viejo y pide un chunk que ya
// no existe, recarga una vez en lugar de romperse.
setupStaleBuildRecovery()

// Sensación de app nativa: sin zoom por pellizco. El meta viewport cubre Android;
// esto cubre iOS, que ignora el meta. Excepción: los QR (ver `data-allow-zoom`).
setupZoomLock()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <SessionProvider>
        <BrowserRouter>
          <Toaster position='bottom-right' />
          <PermissionsModal />
          <App />
        </BrowserRouter>
      </SessionProvider>
    </QueryClientProvider>
  </StrictMode>
)

// WebMCP dev-only: expone tools a agentes de IA en el browser.
// `import.meta.env.DEV` es constante en build → en `vite build` esta rama es
// dead-code y el provider (con su import dinámico) NUNCA entra al bundle de
// producción: cero superficie en prod, sin importar nada más.
// Por defecto se activa en desarrollo; poné `VITE_WEBMCP_ENABLED=false` en tu
// `.env` para apagarlo (kill-switch).
if (import.meta.env.DEV && import.meta.env.VITE_WEBMCP_ENABLED !== 'false') {
  import('@/dev/webmcp/provider')
    .then(({ registerWebMcpProvider }) => registerWebMcpProvider())
    .catch((error) => console.error('[WebMCP] no se pudo cargar el provider dev', error))
}
