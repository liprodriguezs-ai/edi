import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { cn } from '@/lib/utils'
import { Calendar, ChevronDown, X } from 'lucide-react'

export interface DateRange {
  from: string | null // ISO date string
  to: string | null
  shortcut?: DateRangeShortcut | null
}

export type DateRangeShortcut = 'overdue' | 'today' | 'this_week' | 'this_month'

const SHORTCUT_LABELS: Record<DateRangeShortcut, string> = {
  overdue: 'Vencidas',
  today: 'Hoy',
  this_week: 'Esta semana',
  this_month: 'Este mes',
}

interface DateRangeFilterProps {
  label: string
  value: DateRange
  onChange: (next: DateRange) => void
  /** Which preset shortcuts to offer */
  shortcuts?: DateRangeShortcut[]
}

export function DateRangeFilter({
  label,
  value,
  onChange,
  shortcuts = ['overdue', 'today', 'this_week', 'this_month'],
}: DateRangeFilterProps) {
  const triggerLabel = value.shortcut
    ? SHORTCUT_LABELS[value.shortcut]
    : value.from || value.to
      ? `${value.from ?? '…'} — ${value.to ?? '…'}`
      : 'Cualquier fecha'

  const hasActiveFilter = Boolean(value.shortcut || value.from || value.to)

  const clear = (event: React.MouseEvent) => {
    event.stopPropagation()
    onChange({ from: null, to: null, shortcut: null })
  }

  return (
    <div className='flex w-full flex-col gap-1 sm:w-56'>
      <span className='text-xs text-muted-foreground font-medium'>{label}</span>
      <Popover>
        <PopoverTrigger asChild>
          <Button
            type='button'
            variant='outline'
            className={cn(
              'justify-between bg-card font-normal',
              !hasActiveFilter && 'text-muted-foreground'
            )}
          >
            <span className='flex items-center gap-2 truncate'>
              <Calendar className='h-3.5 w-3.5 opacity-60' />
              <span className='truncate'>{triggerLabel}</span>
            </span>
            <span className='flex items-center gap-1'>
              {hasActiveFilter && (
                <span
                  role='button'
                  tabIndex={0}
                  onClick={clear}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                      e.preventDefault()
                      clear(e as unknown as React.MouseEvent)
                    }
                  }}
                  className='rounded p-0.5 hover:bg-accent'
                  aria-label='Limpiar filtro'
                >
                  <X className='h-3.5 w-3.5' />
                </span>
              )}
              <ChevronDown className='h-4 w-4 shrink-0 opacity-50' />
            </span>
          </Button>
        </PopoverTrigger>
        <PopoverContent className='w-[280px] p-2' align='start'>
          <div className='flex flex-col gap-2'>
            <div className='flex flex-wrap gap-1'>
              {shortcuts.map((s) => (
                <Button
                  key={s}
                  type='button'
                  size='sm'
                  variant={value.shortcut === s ? 'default' : 'outline'}
                  className='h-7 text-xs'
                  onClick={() => onChange({ from: null, to: null, shortcut: s })}
                >
                  {SHORTCUT_LABELS[s]}
                </Button>
              ))}
            </div>
            <div className='flex items-center gap-1'>
              <Input
                type='date'
                value={value.from ?? ''}
                onChange={(e) =>
                  onChange({
                    from: e.target.value || null,
                    to: value.to,
                    shortcut: null,
                  })
                }
                className='h-8 bg-card text-xs'
              />
              <span className='text-muted-foreground text-xs'>–</span>
              <Input
                type='date'
                value={value.to ?? ''}
                onChange={(e) =>
                  onChange({
                    from: value.from,
                    to: e.target.value || null,
                    shortcut: null,
                  })
                }
                className='h-8 bg-card text-xs'
              />
            </div>
          </div>
        </PopoverContent>
      </Popover>
    </div>
  )
}

/**
 * Formats a Date using LOCAL calendar parts to produce a YYYY-MM-DD string.
 * Using toISOString() would shift the date for negative UTC offsets (e.g.
 * America/Mexico_City is UTC-5/6, so midnight local = 05:00/06:00 UTC the
 * SAME day, meaning toISOString() yields the PREVIOUS day's date).
 */
function toLocalDateString(date: Date): string {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

/**
 * Converts a DateRange into a concrete {from, to} YYYY-MM-DD pair by resolving
 * shortcuts against "now". The backend compares against `date`-cast columns
 * (due_date, issued_at) which have no time component, so plain date strings
 * avoid UTC timezone off-by-one errors.
 *
 * Manual picker inputs (non-shortcut path) already arrive as YYYY-MM-DD strings
 * from the <input type="date"> element and pass through unchanged.
 */
export function resolveDateRange(
  range: DateRange,
  now: Date = new Date()
): { from: string | null; to: string | null } {
  if (range.shortcut) {
    // Build local-date anchors using local year/month/day to avoid UTC shift.
    const y = now.getFullYear()
    const mo = now.getMonth()
    const d = now.getDate()
    const today = new Date(y, mo, d) // midnight local
    switch (range.shortcut) {
      case 'overdue': {
        // All dates strictly before today — upper bound is yesterday.
        const yesterday = new Date(y, mo, d - 1)
        return { from: null, to: toLocalDateString(yesterday) }
      }
      case 'today':
        return { from: toLocalDateString(today), to: toLocalDateString(today) }
      case 'this_week': {
        const dayOfWeek = today.getDay() // 0 = Sunday
        const startOfWeek = new Date(y, mo, d - (dayOfWeek === 0 ? 6 : dayOfWeek - 1))
        const endOfWeek = new Date(startOfWeek)
        endOfWeek.setDate(startOfWeek.getDate() + 6)
        return { from: toLocalDateString(startOfWeek), to: toLocalDateString(endOfWeek) }
      }
      case 'this_month': {
        const startOfMonth = new Date(y, mo, 1)
        const endOfMonth = new Date(y, mo + 1, 0) // last day of current month
        return { from: toLocalDateString(startOfMonth), to: toLocalDateString(endOfMonth) }
      }
    }
  }
  return { from: range.from, to: range.to }
}
