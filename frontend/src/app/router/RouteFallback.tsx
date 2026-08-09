/**
 * Fallback de Suspense para rutas lazy: spinner liviano que conserva el layout.
 */
export const RouteFallback = () => {
  return (
    <div className="flex flex-1 items-center justify-center py-24" role="status" aria-live="polite">
      <div className="flex items-center gap-3 text-muted-foreground">
        <span
          className="h-5 w-5 animate-spin rounded-full border-2 border-current border-t-transparent"
          aria-hidden="true"
        />
        <span className="text-sm">Cargando…</span>
      </div>
    </div>
  )
}
