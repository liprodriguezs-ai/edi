import type { LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'

import { cn } from '@/lib/utils'

interface InlineEmptyProps {
  children: ReactNode
  icon?: LucideIcon
  className?: string
}

/**
 * Small empty message inside a section, tab or panel. For a whole list use
 * `TableEmptyState`; for a missing record use `EntityNotFound`.
 */
export function InlineEmpty({ children, icon: Icon, className }: InlineEmptyProps) {
  return (
    <div role='status' className={cn('flex items-center gap-2 py-2 text-sm text-muted-foreground', className)}>
      {Icon && <Icon className='size-4 shrink-0' aria-hidden />}
      <span>{children}</span>
    </div>
  )
}
