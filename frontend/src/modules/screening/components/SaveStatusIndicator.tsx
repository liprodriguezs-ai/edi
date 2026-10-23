import { Badge } from '@/components/ui/badge'
import type { SyncStatus } from '@/modules/screening/store/screeningDraftStore'
import { CheckCircle2, CloudOff, Loader2, Lock } from 'lucide-react'

const COPY: Record<SyncStatus, { label: string; icon: typeof CheckCircle2 }> = {
  idle: { label: 'Sin cambios', icon: CheckCircle2 },
  saving: { label: 'Guardando…', icon: Loader2 },
  saved: { label: 'Guardado', icon: CheckCircle2 },
  offline: { label: 'Sin conexión — se reintentará', icon: CloudOff },
  frozen: { label: 'Congelado — solo lectura', icon: Lock },
}

/** D3 — persistent status indicator: idle/saving/saved/offline/frozen. */
export function SaveStatusIndicator({ status }: { status: SyncStatus }) {
  const { label, icon: Icon } = COPY[status]

  return (
    <Badge variant="outline" className="gap-1.5">
      <Icon className={status === 'saving' ? 'h-3.5 w-3.5 animate-spin' : 'h-3.5 w-3.5'} aria-hidden />
      {label}
    </Badge>
  )
}
