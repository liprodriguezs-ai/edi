import type { ReactNode } from 'react'

import { cn } from '@/lib/utils'

interface SelectableCardProps {
  selected: boolean
  onSelect: () => void
  children: ReactNode
  disabled?: boolean
  className?: string
  /** `radio` when the cards sit inside a `role='radiogroup'` (aria-checked instead of aria-pressed). */
  role?: 'radio'
}

/**
 * Clickable option card (plans, packages). Square corners, primary border when
 * selected. The content is free-form; the card only owns the selection chrome.
 */
export function SelectableCard({ selected, onSelect, children, disabled = false, className, role }: SelectableCardProps) {
  return (
    <button
      type='button'
      onClick={onSelect}
      disabled={disabled}
      role={role}
      aria-checked={role === 'radio' ? selected : undefined}
      aria-pressed={role === 'radio' ? undefined : selected}
      className={cn(
        'w-full rounded-none border px-4 py-3 text-left transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50',
        selected ? 'border-primary bg-primary/5' : 'border-border bg-card hover:border-primary/40',
        className
      )}
    >
      {children}
    </button>
  )
}
