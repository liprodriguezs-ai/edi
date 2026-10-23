import type { ReactNode } from 'react'

import { cn } from '@/lib/utils'

interface SectionLabelProps {
  children: ReactNode
  /** Semantic element; defaults to a paragraph (visual eyebrow only). */
  as?: 'p' | 'h2' | 'h3' | 'span'
  className?: string
}

/** Mono uppercase eyebrow that labels a section ("RESUMEN DEL PERIODO", "EJES"). */
export function SectionLabel({ children, as: Tag = 'p', className }: SectionLabelProps) {
  return (
    <Tag className={cn('font-mono text-[11px] font-medium uppercase tracking-[0.14em] text-muted-foreground', className)}>
      {children}
    </Tag>
  )
}
