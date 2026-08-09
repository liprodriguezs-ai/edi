import { LayoutContext } from '@/app/layout/useLayoutContext'
import { RouteErrorBoundary } from '@/app/router/RouteErrorBoundary'
import { RouteFallback } from '@/app/router/RouteFallback'
import { AppSidebar } from '@/components/app-sidebar'
import { SiteHeader } from '@/components/site-header'
import { SidebarInset, SidebarProvider } from '@/components/ui/sidebar'
import { cn } from '@/lib/utils'
import { UpcomingChargeBanner } from '@/modules/checkoutSubscription/components/UpcomingChargeBanner'
import { useFirebaseMessaging } from '@/shared/firebase/useFirebaseMessaging'
import { Suspense, useState } from 'react'
import { Outlet } from 'react-router-dom'

export type DashboardLayoutContext = {
  setFullWidth: (_value: boolean) => void
}

export function DashboardLayout() {
  const [fullWidth, setFullWidth] = useState(false)
  const layoutContextValue: DashboardLayoutContext = { setFullWidth }

  // Inicializar Firebase Cloud Messaging para push notifications
  useFirebaseMessaging()

  return (
    <LayoutContext.Provider value={layoutContextValue}>
    <SidebarProvider
      style={
        {
          '--sidebar-width': 'calc(var(--spacing) * 72)',
          '--header-height': 'calc(var(--spacing) * 12)',
        } as React.CSSProperties
      }
    >
      <AppSidebar variant="inset" />
      <SidebarInset className="bg-muted min-w-0 max-w-full overflow-x-clip">
        <SiteHeader />
        <div className="flex min-w-0 flex-1 flex-col">
          <div className={cn(
            '@container/main flex min-w-0 max-w-full flex-1 flex-col gap-2 p-3 sm:p-4 lg:p-6 w-full',
            !fullWidth && 'max-w-7xl mx-auto',
          )}>
            {/* Se pinta a sí mismo sólo en la última semana de la prueba: la fecha
                del primer cargo vivía únicamente en pantallas que el dueño deja de
                visitar, así que el cargo podía llegarle sin haberlo visto venir. */}
            <UpcomingChargeBanner />

            {/* Boundary interno: la carga de cada página lazy NO desmonta el layout */}
            <RouteErrorBoundary>
              <Suspense fallback={<RouteFallback />}>
                <Outlet />
              </Suspense>
            </RouteErrorBoundary>
          </div>
        </div>
      </SidebarInset>
    </SidebarProvider>
    </LayoutContext.Provider>
  )
}
