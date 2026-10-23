import type { LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'

import { cn } from '@/lib/utils'

type CalloutTone = 'brand' | 'info' | 'warning'

const TONE: Record<CalloutTone, string> = {
  // Brand green, same visual language as the "Vista previa" banner.
  brand: 'bg-[var(--green-bg)] text-[var(--green-deep)]',
  info: 'border-l-2 border-[var(--b)] bg-[var(--bbg)] text-foreground',
  warning: 'border-l-2 border-[var(--y)] bg-[var(--ybg)] text-foreground',
}

interface CalloutProps {
  icon: LucideIcon
  children: ReactNode
  tone?: CalloutTone
  className?: string
}

/**
 * Inline notice for notes/context inside forms and screens. The icon is a
 * lucide-react component. `warning` is a UI warning, not the clinical semaphore.
 */
export function Callout({ icon: Icon, children, tone = 'brand', className }: CalloutProps) {
  return (
    <div
      className={cn(
        'flex items-start gap-2 rounded-none px-3.5 py-2.5 text-[12.5px] leading-relaxed',
        TONE[tone],
        className
      )}
    >
      <Icon className='mt-0.5 size-4 flex-none' aria-hidden />
      <div className='min-w-0 flex-1'>{children}</div>
    </div>
  )
}

export type { CalloutTone }
