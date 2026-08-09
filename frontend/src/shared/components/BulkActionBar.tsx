import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { X } from 'lucide-react'
import type { ReactNode } from 'react'

interface BulkActionBarProps {
  count: number
  onClear: () => void
  children: ReactNode
  /** Override positioning for embedded usage (e.g., inside a Sheet) */
  className?: string
}

/**
 * Fixed bottom bar that surfaces when a list has selected rows. Houses bulk
 * action buttons passed as children. Disappears when `count === 0`.
 */
export function BulkActionBar({ count, onClear, children, className }: BulkActionBarProps) {
  if (count === 0) return null

  return (
    <div
      className={cn(
        'fixed bottom-4 left-1/2 z-40 -translate-x-1/2',
        'flex items-center gap-3 rounded-full border bg-background px-4 py-2 shadow-lg',
        'animate-in fade-in slide-in-from-bottom-4',
        className,
      )}
      role="region"
      aria-label="Acciones en lote"
    >
      <Button
        type="button"
        variant="ghost"
        size="sm"
        onClick={onClear}
        aria-label="Limpiar selección"
        className="h-8 w-8 p-0"
      >
        <X className="h-4 w-4" />
      </Button>

      <span className="text-sm font-medium tabular-nums">
        {count} {count === 1 ? 'seleccionado' : 'seleccionados'}
      </span>

      <span className="h-5 w-px bg-border" aria-hidden />

      <div className="flex items-center gap-2">{children}</div>
    </div>
  )
}
