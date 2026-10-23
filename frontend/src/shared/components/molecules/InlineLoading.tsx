import { Skeleton } from 'boneyard-js/react'

import { cn } from '@/lib/utils'

interface InlineLoadingProps {
  /** Unique boneyard name for this placeholder. */
  name: string
  /** Number of skeleton lines. */
  lines?: number
  className?: string
}

/**
 * Loading placeholder for a section, tab or panel (replaces plain "Cargando…" text).
 * For a full page use `PageLoader`; for a list use `TableLoadingState`.
 */
export function InlineLoading({ name, lines = 3, className }: InlineLoadingProps) {
  return (
    <div aria-busy='true' aria-label='Cargando' className={className}>
      <Skeleton name={name} loading>
        <div className={cn('space-y-2')}>
          {Array.from({ length: lines }).map((_, index) => (
            <div key={index} className={cn('h-4 bg-muted', index === lines - 1 ? 'w-2/3' : 'w-full')} />
          ))}
        </div>
      </Skeleton>
    </div>
  )
}
