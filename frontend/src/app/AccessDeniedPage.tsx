import { IconLock } from '@tabler/icons-react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { useSession } from '@/shared/auth/useSession'
import { ERoleUserSlug, roleRoutes } from '@/shared/interfaces/Entity'
import { useMemo } from 'react'

const DEFAULT_HOME = '/dashboard'

/**
 * Página mostrada cuando el usuario intenta acceder a una ruta protegida por permiso o rol
 * y no tiene acceso. Mensaje claro de "zona protegida" y acciones para volver.
 */
export function AccessDeniedPage() {
  const location = useLocation()
  const navigate = useNavigate()
  const { role } = useSession()

  const homePath = useMemo(() => {
    const slug = role as ERoleUserSlug
    return roleRoutes[slug] ?? DEFAULT_HOME
  }, [role])

  const from = location.state && typeof location.state === 'object' && 'from' in location.state
    ? (location.state as { from: { pathname: string } }).from
    : null

  const canGoBack = Boolean(from?.pathname && from.pathname !== '/acceso-denegado')

  return (
    <main
      className="flex flex-1 flex-col items-center justify-center p-4 md:p-6"
      id="access-denied-main"
      aria-labelledby="access-denied-title"
    >
      <Card className="w-full max-w-md border-border">
        <CardHeader className="text-center">
          <div
            className="mx-auto mb-2 flex size-12 items-center justify-center rounded-full bg-muted"
            aria-hidden
          >
            <IconLock className="size-6 text-muted-foreground" />
          </div>
          <CardTitle id="access-denied-title" className="text-xl md:text-2xl">
            Zona protegida
          </CardTitle>
          <CardDescription id="access-denied-desc" className="text-base">
            No tienes permisos para ver esta sección. Si crees que deberías tener acceso, contacta
            al administrador.
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <div
            role="alert"
            aria-live="polite"
            className="rounded-md border border-border bg-muted/50 px-3 py-2 text-sm text-muted-foreground"
          >
            Esta ruta está restringida por permisos o rol de usuario.
          </div>
          <div className="flex flex-col gap-2 sm:flex-row sm:justify-center">
            {canGoBack && (
              <Button
                variant="outline"
                onClick={() => navigate(-1)}
                className="min-w-[140px]"
              >
                Volver atrás
              </Button>
            )}
            <Button asChild variant="default" className="min-w-[140px]">
              <Link to={homePath}>Volver al inicio</Link>
            </Button>
          </div>
        </CardContent>
      </Card>
    </main>
  )
}
