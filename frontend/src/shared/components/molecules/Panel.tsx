import { useId, type ReactNode } from 'react'

import { cn } from '@/lib/utils'
import { SectionLabel } from '@/shared/components/atoms/SectionLabel'

interface PanelProps {
  children?: ReactNode
  /** Mono uppercase label above the title. */
  eyebrow?: ReactNode
  title?: ReactNode
  description?: ReactNode
  actions?: ReactNode
  /** `p-5` by default; `none` when the content draws its own padding (tables, lists). */
  padding?: 'default' | 'none'
  className?: string
  as?: 'section' | 'div'
}

/**
 * Content section in the "canto vivo" language: square corners, hairline border,
 * card background. Replaces hand-rolled `rounded-xl border bg-card p-6` blocks.
 */
export function Panel({
  children,
  eyebrow,
  title,
  description,
  actions,
  padding = 'default',
  className,
  as: Tag = 'section',
}: PanelProps) {
  const hasHeader = Boolean(eyebrow || title || description || actions)
  const titleId = useId()
  const hasBody = children !== undefined && children !== null && children !== false

  return (
    <Tag
      aria-labelledby={title ? titleId : undefined}
      className={cn('rounded-none border border-border bg-card', padding === 'default' && 'p-5', className)}
    >
      {hasHeader && (
        <div
          className={cn(
            'flex flex-wrap items-start justify-between gap-3',
            padding === 'default' ? hasBody && 'mb-4' : 'px-5 py-4',
            padding === 'none' && hasBody && 'border-b border-border'
          )}
        >
          <div className='min-w-0 space-y-1'>
            {eyebrow && <SectionLabel>{eyebrow}</SectionLabel>}
            {title && (
              <h2 id={titleId} className='text-base font-semibold tracking-tight'>
                {title}
              </h2>
            )}
            {description && <p className='text-sm text-muted-foreground'>{description}</p>}
          </div>
          {actions && <div className='flex shrink-0 flex-wrap items-center gap-2'>{actions}</div>}
        </div>
      )}
      {children}
    </Tag>
  )
}
