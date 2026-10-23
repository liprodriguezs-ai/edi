import type { ReactNode } from 'react'

import { Field } from '@/components/ui/field'
import { cn } from '@/lib/utils'

/** Right-aligned action row at the bottom of an inline form (Cancelar / Guardar). */
export function FormFooter({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <Field orientation='horizontal' className={cn('justify-end border-t border-border pt-6', className)}>
      {children}
    </Field>
  )
}
