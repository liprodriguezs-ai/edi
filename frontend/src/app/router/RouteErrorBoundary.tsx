import { Button } from '@/components/ui/button'
import { isStaleBuildError, reloadForStaleBuild } from '@/shared/pwa/staleBuildRecovery'
import { Component, type ErrorInfo, type ReactNode } from 'react'

interface RouteErrorBoundaryProps {
  children: ReactNode
}

interface RouteErrorBoundaryState {
  hasError: boolean
  isStaleBuild: boolean
}

/**
 * Captura errores de render y fallos de carga de chunks lazy (p. ej. tras un deploy
 * que invalida los hashes).
 *
 * Si el fallo es por un build viejo, recarga sola: el usuario no tiene por qué
 * entender que su pestaña quedó desfasada de un deploy. La recarga tiene
 * enfriamiento (ver staleBuildRecovery.ts), así que si no alcanza para arreglarlo
 * cae al mensaje con el botón manual en vez de entrar en bucle.
 */
export class RouteErrorBoundary extends Component<
  RouteErrorBoundaryProps,
  RouteErrorBoundaryState
> {
  state: RouteErrorBoundaryState = { hasError: false, isStaleBuild: false }

  static getDerivedStateFromError(error: unknown): RouteErrorBoundaryState {
    return { hasError: true, isStaleBuild: isStaleBuildError(error) }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    if (isStaleBuildError(error)) {
      console.warn('[PWA] la pestaña quedó en un build viejo, recargando', error)
      reloadForStaleBuild()
      return
    }

    console.error('Error al cargar la ruta', error, info)
  }

  handleReload = () => {
    window.location.reload()
  }

  render() {
    if (this.state.hasError) {
      const message = this.state.isStaleBuild
        ? 'La aplicación se actualizó mientras la tenías abierta. Recarga para continuar.'
        : 'Ocurrió un error al cargar esta sección. Verifica tu conexión e inténtalo de nuevo.'

      return (
        <div className='flex flex-1 flex-col items-center justify-center gap-4 py-24 text-center'>
          <p className='text-sm text-muted-foreground'>{message}</p>
          <Button type='button' variant='outline' onClick={this.handleReload}>
            Recargar página
          </Button>
        </div>
      )
    }

    return this.props.children
  }
}
