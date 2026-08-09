import type { IAudit } from '@/modules/audit/schema'
import { parseAuditValues } from '@/modules/audit/schema'
import { Button } from '@/components/ui/button'
import { Modal } from '@/shared/components/Modal'
import { format } from 'date-fns'
import { es } from 'date-fns/locale'
import { ArrowRight, Minus, Plus } from 'lucide-react'

interface AuditDiffModalProps {
  isOpen: boolean
  audit: IAudit | null
  onClose: () => void
}

type DiffKind = 'added' | 'removed' | 'changed'

interface DiffEntry {
  key: string
  kind: DiffKind
  oldVal: unknown
  newVal: unknown
}

/** Human-readable field name (snake_case → Title Case) */
function fieldLabel(key: string): string {
  return key
    .split('_')
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase())
    .join(' ')
}

function formatValue(value: unknown): string {
  if (value === null || value === undefined) return '—'
  if (typeof value === 'object') {
    try {
      return JSON.stringify(value, null, 2)
    } catch {
      return String(value)
    }
  }
  if (typeof value === 'boolean') return value ? 'Sí' : 'No'
  const s = String(value)
  if (s.match(/^\d{4}-\d{2}-\d{2}/)) {
    try {
      return format(new Date(s), 'd MMM yyyy', { locale: es })
    } catch {
      return s
    }
  }
  return s
}

function valueEqual(a: unknown, b: unknown): boolean {
  if (a === b) return true
  if (a == null && b == null) return true
  if (typeof a === 'object' && typeof b === 'object') {
    try {
      return JSON.stringify(a) === JSON.stringify(b)
    } catch {
      return false
    }
  }
  return String(a) === String(b)
}

function getDiffEntries(audit: IAudit): DiffEntry[] {
  let oldV = audit.old_values ?? {}
  let newV = audit.new_values ?? {}

  const raw = audit as Record<string, unknown>
  if (raw.new_value !== undefined && Object.keys(newV).length === 0) {
    newV = parseAuditValues(raw.new_value)
  }
  if (raw.old_value !== undefined && Object.keys(oldV).length === 0) {
    oldV = parseAuditValues(raw.old_value)
  }

  const allKeys = new Set([...Object.keys(oldV), ...Object.keys(newV)])
  const entries: DiffEntry[] = []

  for (const key of Array.from(allKeys).sort((a, b) => a.localeCompare(b))) {
    const oldVal = oldV[key]
    const newVal = newV[key]
    const hadOld = key in oldV
    const hasNew = key in newV

    if (!hadOld && hasNew) {
      entries.push({ key, kind: 'added', oldVal: undefined, newVal })
    } else if (hadOld && !hasNew) {
      entries.push({ key, kind: 'removed', oldVal, newVal: undefined })
    } else if (!valueEqual(oldVal, newVal)) {
      entries.push({ key, kind: 'changed', oldVal, newVal })
    }
  }

  return entries
}

const EVENT_LABELS: Record<IAudit['event'], string> = {
  created: 'Creado',
  updated: 'Actualizado',
  deleted: 'Eliminado',
  restored: 'Restaurado',
}

function auditableLabel(audit: IAudit): string {
  const type = audit.auditable_type ?? '—'
  const shortType = type.split('\\').pop() ?? type
  return `${shortType} (${audit.auditable_id.slice(0, 8)}…)`
}

export function AuditDiffModal({ isOpen, audit, onClose }: AuditDiffModalProps) {
  if (!isOpen) return null

  const entries = audit ? getDiffEntries(audit) : []
  const dateStr = audit?.created_at
    ? format(new Date(audit.created_at), 'd MMM yyyy, HH:mm', { locale: es })
    : '—'

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Cambios realizados"
      description={
        audit ? (
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-muted-foreground">
            <span className="font-medium text-foreground">{EVENT_LABELS[audit.event]}</span>
            <span aria-hidden className="text-border">·</span>
            <span>{dateStr}</span>
            <span aria-hidden className="text-border">·</span>
            <span>{auditableLabel(audit)}</span>
          </div>
        ) : undefined
      }
      size="xl"
      footer={
        <Button variant="outline" onClick={onClose}>
          Cerrar
        </Button>
      }
    >
      <div className="pb-6">
        {entries.length === 0 ? (
          <p className="rounded-lg border border-dashed border-border bg-muted/30 px-4 py-8 text-center text-sm text-muted-foreground">
            No hay diferencias en este registro.
          </p>
        ) : (
          <ul className="space-y-1" role="list" aria-label="Lista de cambios">
            {entries.map(({ key, kind, oldVal, newVal }) => (
              <li
                key={key}
                className="flex items-start gap-3 rounded-lg border border-transparent px-3 py-2.5 transition-colors hover:bg-muted/40"
              >
                <span className="mt-0.5 shrink-0" aria-hidden>
                  {kind === 'added' && (
                    <span className="flex h-6 w-6 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400">
                      <Plus className="h-3.5 w-3.5" strokeWidth={2.5} />
                    </span>
                  )}
                  {kind === 'removed' && (
                    <span className="flex h-6 w-6 items-center justify-center rounded-full bg-red-500/15 text-red-600 dark:bg-red-500/20 dark:text-red-400">
                      <Minus className="h-3.5 w-3.5" strokeWidth={2.5} />
                    </span>
                  )}
                  {kind === 'changed' && (
                    <span className="flex h-6 w-6 items-center justify-center rounded-full bg-amber-500/15 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400">
                      <ArrowRight className="h-3.5 w-3.5" strokeWidth={2.5} />
                    </span>
                  )}
                </span>
                <div className="min-w-0 flex-1">
                  <div className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
                    {fieldLabel(key)}
                  </div>
                  <div className="mt-0.5 flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm">
                    {kind === 'added' && (
                      <span className="max-w-full rounded bg-emerald-500/10 px-1.5 py-0.5 font-mono text-emerald-700 break-words dark:bg-emerald-500/15 dark:text-emerald-300">
                        {formatValue(newVal)}
                      </span>
                    )}
                    {kind === 'removed' && (
                      <span className="max-w-full rounded bg-red-500/10 px-1.5 py-0.5 font-mono text-red-700 line-through break-words dark:bg-red-500/15 dark:text-red-300">
                        {formatValue(oldVal)}
                      </span>
                    )}
                    {kind === 'changed' && (
                      <>
                        <span className="max-w-full rounded bg-red-500/10 px-1.5 py-0.5 font-mono text-red-700 line-through break-words dark:bg-red-500/15 dark:text-red-300">
                          {formatValue(oldVal)}
                        </span>
                        <span className="shrink-0 text-muted-foreground" aria-hidden>
                          →
                        </span>
                        <span className="max-w-full rounded bg-emerald-500/10 px-1.5 py-0.5 font-mono text-emerald-700 break-words dark:bg-emerald-500/15 dark:text-emerald-300">
                          {formatValue(newVal)}
                        </span>
                      </>
                    )}
                  </div>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Modal>
  )
}
