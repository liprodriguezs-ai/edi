import { cn } from '@/lib/utils'

/**
 * Leyenda compartida "* Datos requeridos" para formularios.
 * El asterisco usa `text-destructive` para igualar el color del `*` que
 * `FieldLabel required` pinta en cada campo. Se coloca arriba del formulario.
 */
export function RequiredFieldsLegend({ className }: { className?: string }) {
  return (
    <p className={cn('text-sm text-muted-foreground', className)}>
      <span className="text-destructive">*</span> Datos requeridos
    </p>
  )
}
