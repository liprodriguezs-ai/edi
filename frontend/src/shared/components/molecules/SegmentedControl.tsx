import { ToggleGroup } from 'radix-ui'
import type { ReactNode } from 'react'

import { cn } from '@/lib/utils'

export interface SegmentedOption<T extends string> {
  value: T
  label: ReactNode
}

interface SegmentedControlProps<T extends string> {
  value: T
  onValueChange: (value: T) => void
  options: ReadonlyArray<SegmentedOption<T>>
  'aria-label': string
  size?: 'sm' | 'default'
  className?: string
}

/**
 * Exclusive choice between a few options (monthly / yearly...). Built on Radix
 * ToggleGroup for roving focus and arrow-key navigation.
 */
export function SegmentedControl<T extends string>({
  value,
  onValueChange,
  options,
  'aria-label': ariaLabel,
  size = 'default',
  className,
}: SegmentedControlProps<T>) {
  return (
    <ToggleGroup.Root
      type='single'
      value={value}
      // Radix emits '' when the active item is clicked again; a segmented control never deselects.
      onValueChange={(next: string) => {
        if (next) onValueChange(next as T)
      }}
      aria-label={ariaLabel}
      className={cn('inline-flex w-full border border-border bg-card p-0.5', className)}
    >
      {options.map((option) => (
        <ToggleGroup.Item
          key={option.value}
          value={option.value}
          className={cn(
            'flex-1 rounded-none px-4 font-semibold text-muted-foreground transition-colors outline-none hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 data-[state=on]:bg-foreground data-[state=on]:text-background',
            size === 'sm' ? 'py-1.5 text-sm' : 'py-2 text-[13px]'
          )}
        >
          {option.label}
        </ToggleGroup.Item>
      ))}
    </ToggleGroup.Root>
  )
}
