import { nutritionalStatusTone } from '@/modules/nutrition/utils/nutritionalStatus'
import { StatusPill } from '@/shared/components/atoms/StatusPill'

interface NutritionalStatusBadgeProps {
  /** Fila de `nutritional_status` tal como la devuelve el API (key + etiqueta). */
  status?: { key: string; name: string } | null
}

/** Estado nutricional con el semáforo clínico (rojo / ámbar / verde / neutro). */
export function NutritionalStatusBadge({ status }: NutritionalStatusBadgeProps) {
  if (!status) {
    return <span className="text-sm text-muted-foreground">—</span>
  }

  return <StatusPill tone={nutritionalStatusTone(status.key)}>{status.name}</StatusPill>
}
