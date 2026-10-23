import type { IAudit } from '@/modules/audit/schema'
import type { PillTone } from '@/shared/components/atoms/StatusPill'

export const AUDIT_EVENT_LABELS: Record<IAudit['event'], string> = {
  created: 'Creado',
  updated: 'Actualizado',
  deleted: 'Eliminado',
  restored: 'Restaurado',
}

/** Non-clinical tones: only "deleted" carries a danger meaning. */
export const AUDIT_EVENT_TONES: Record<IAudit['event'], PillTone> = {
  created: 'b',
  updated: 'n',
  deleted: 'r',
  restored: 'b',
}

export function auditableLabel(audit: IAudit): string {
  const type = audit.auditable_type ?? '—'
  const shortType = type.split('\\').pop() ?? type
  return `${shortType} (${audit.auditable_id.slice(0, 8)}…)`
}
