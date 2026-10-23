import type { ReactNode } from 'react'

import { cn } from '@/lib/utils'

const COLUMNS = {
  1: 'grid-cols-1',
  2: 'grid-cols-1 sm:grid-cols-2',
  3: 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
  4: 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-4',
} as const

interface DescriptionListProps {
  children: ReactNode
  columns?: keyof typeof COLUMNS
  /** `bordered`: hairline grid of cells (detail pages). `plain`: no borders (inside panels/sheets). */
  variant?: 'bordered' | 'plain'
  className?: string
}

/** Read-only "label: value" grid for detail screens. */
export function DescriptionList({ children, columns = 3, variant = 'bordered', className }: DescriptionListProps) {
  return (
    <dl
      data-variant={variant}
      className={cn(
        'group/dl grid',
        COLUMNS[columns],
        variant === 'bordered' ? 'border-t border-l border-border bg-card' : 'gap-x-6 gap-y-3',
        className
      )}
    >
      {children}
    </dl>
  )
}

interface DescriptionItemProps {
  label: ReactNode
  value: ReactNode
  /** Monospace + tabular numbers (folios, hashes, dates). */
  mono?: boolean
  /** Secondary line under the value (e.g. a warning or clarification). */
  hint?: ReactNode
  className?: string
}

export function DescriptionItem({ label, value, mono = false, hint, className }: DescriptionItemProps) {
  return (
    <div
      className={cn(
        'min-w-0 group-data-[variant=bordered]/dl:border-r group-data-[variant=bordered]/dl:border-b group-data-[variant=bordered]/dl:border-border group-data-[variant=bordered]/dl:px-4 group-data-[variant=bordered]/dl:py-3',
        className
      )}
    >
      <dt className='text-xs font-medium text-muted-foreground'>{label}</dt>
      <dd className={cn('mt-1 text-sm', mono && 'font-mono tabular-nums break-all')}>{value}</dd>
      {hint && <p className='mt-0.5 text-xs text-primary'>{hint}</p>}
    </div>
  )
}
