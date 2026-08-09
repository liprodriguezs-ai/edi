import type { ReactNode } from 'react'

/**
 * Layout split de las pantallas de acceso (login / registro):
 * panel de marca a la izquierda + formulario a la derecha sobre el lienzo Salvia.
 * En mobile el panel de marca se oculta y el formulario ocupa todo el ancho.
 * Debe vivir dentro del scope `.salvia`.
 */
export function AuthSplitLayout({
  brand,
  children,
}: {
  brand: ReactNode
  children: ReactNode
}) {
  return (
    <div className="grid min-h-dvh md:min-h-svh md:grid-cols-[1.05fr_1fr]">
      {brand}
      <div className="flex items-center justify-center bg-muted px-4 py-8 pb-[max(2rem,env(safe-area-inset-bottom))] pt-[max(2rem,env(safe-area-inset-top))] sm:px-6 sm:py-10">
        <div className="w-full max-w-[400px]">{children}</div>
      </div>
    </div>
  )
}
