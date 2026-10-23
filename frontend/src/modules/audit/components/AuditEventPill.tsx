import { AUDIT_EVENT_LABELS, AUDIT_EVENT_TONES } from '@/modules/audit/auditPresentation'
import type { IAudit } from '@/modules/audit/schema'
import { StatusPill } from '@/shared/components/atoms/StatusPill'

export function AuditEventPill({ event }: { event: IAudit['event'] }) {
  return <StatusPill tone={AUDIT_EVENT_TONES[event]}>{AUDIT_EVENT_LABELS[event]}</StatusPill>
}
