import type { AreaCaptureStatus } from '@/modules/screening/utils/areaProgress'

export interface AreaNavEntry {
  anchorId: string
  label: string
  status: AreaCaptureStatus
  answered: number
  total: number
}

interface AreaNavProps {
  entries: AreaNavEntry[]
  onGoTo: (_anchorId: string) => void
}

/**
 * Sticky section chips: where each part of the Prueba EDI stands, one tap
 * away. Capture states use the brand/neutral tones -- the green/yellow/red
 * tokens are reserved for the clinical result -- and the warning tokens
 * (`--y`/`--ybg`) only for "needs the previous group", the same language as
 * the alternate block.
 */
const STATUS_CLASS: Record<AreaCaptureStatus, string> = {
  complete: 'border-transparent bg-[var(--bbg)] text-[var(--b)]',
  pending: 'border-border bg-card text-muted-foreground',
  needs_alternate: 'border-[var(--y)] bg-[var(--ybg)] text-[var(--y)]',
}

function statusText(entry: AreaNavEntry): string {
  if (entry.status === 'complete') return 'listo'
  if (entry.status === 'needs_alternate') return 'grupo anterior'
  return `${entry.answered}/${entry.total}`
}

export function AreaNav({ entries, onGoTo }: AreaNavProps) {
  return (
    <nav
      aria-label="Secciones de la Prueba EDI"
      className="sticky top-0 z-10 -mx-4 border-b border-border bg-background/95 px-4 py-2 backdrop-blur sm:-mx-6 sm:px-6"
    >
      {/* One scrollable row on phones (a wrapped block ate a third of the
          screen); wraps from `sm` up. */}
      <ul className="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-0.5 sm:flex-wrap sm:overflow-visible">
        {entries.map((entry) => (
          <li key={entry.anchorId} className="shrink-0">
            <a
              href={`#${entry.anchorId}`}
              onClick={(event) => {
                event.preventDefault()
                onGoTo(entry.anchorId)
              }}
              className={`inline-flex min-h-8 items-center gap-1.5 whitespace-nowrap border px-2.5 text-xs font-semibold transition-colors hover:border-primary ${STATUS_CLASS[entry.status]}`}
            >
              {entry.label}
              <span className="font-mono font-normal tabular-nums">{statusText(entry)}</span>
            </a>
          </li>
        ))}
      </ul>
    </nav>
  )
}
