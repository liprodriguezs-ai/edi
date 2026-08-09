import type { IAgingPackageEntry } from '@/modules/package/schema'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { TriangleAlert } from 'lucide-react'
import { useState } from 'react'

/** Color del bucket de antigüedad (fondo + texto). */
function agingBucketStyle(label: string): string {
  if (label === 'Más de 7 días') return 'bg-red-50 text-red-700 border-red-200'
  if (label === '3 a 7 días') return 'bg-amber-50 text-amber-700 border-amber-200'
  return 'bg-blue-50 text-blue-700 border-blue-200'
}

interface AgingBucketCardProps {
  label: string
  count: number
  entries: IAgingPackageEntry[]
  isOver7?: boolean
}

/**
 * Card que muestra el total de paquetes en un bucket de antigüedad y permite
 * expandir el listado de entradas individuales.
 */
export function AgingBucketCard({ label, count, entries, isOver7 }: AgingBucketCardProps) {
  const [expanded, setExpanded] = useState(false)
  const styleClass = agingBucketStyle(label)

  return (
    <div className={`rounded-[12px] border p-4 ${styleClass}`}>
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          {isOver7 && <TriangleAlert className="size-4" aria-hidden />}
          <span className="text-sm font-semibold">{label}</span>
        </div>
        <span className="text-[22px] font-bold tabular-nums">{count}</span>
      </div>
      {count > 0 && (
        <button
          type="button"
          className="mt-2 text-[12px] underline underline-offset-2 opacity-70 hover:opacity-100"
          onClick={() => setExpanded((prev) => !prev)}
          aria-expanded={expanded}
        >
          {expanded ? 'Ocultar detalle' : 'Ver detalle'}
        </button>
      )}
      {expanded && (
        <ul className="mt-3 space-y-2" role="list">
          {entries.map((entry) => (
            <li
              key={entry.id}
              className="rounded-[8px] border border-current/10 bg-white/60 px-3 py-2 text-[12.5px]"
            >
              <span className="font-medium">{entry.unit_full_identifier}</span>
              {entry.carrier && (
                <span className="ml-1.5 opacity-70">· {entry.carrier}</span>
              )}
              <span className="ml-1.5 font-semibold">{entry.days_pending}d</span>
              {entry.storage_location && (
                <span className="ml-1.5 opacity-70">· {entry.storage_location}</span>
              )}
              {entry.received_at && (
                <span className="ml-1.5 opacity-50">
                  {displayFormatDate(entry.received_at, 'mediumDate')}
                </span>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
