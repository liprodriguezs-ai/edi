import { cn } from '@/lib/utils'
import { Check, ChevronDown } from 'lucide-react'
import { useState } from 'react'

interface IncludedFeaturesProps {
  features: string[]
  title: string
  description?: string
  /** DOM id of the list, referenced by the mobile toggle (`aria-controls`). */
  listId: string
  className?: string
}

/**
 * What a plan includes. On mobile the list collapses behind a "Ver todo" toggle so
 * it does not push the quote/summary off screen. Shared by the checkout plan builder
 * and the change-plan picker.
 */
export function IncludedFeatures({ features, title, description, listId, className }: IncludedFeaturesProps) {
  const [expanded, setExpanded] = useState(false)

  if (features.length === 0) {
    return null
  }

  return (
    <div className={className}>
      <p className='text-[13px] font-semibold text-foreground'>{title}</p>
      {description && <p className='mt-1 text-[12.5px] text-muted-foreground'>{description}</p>}

      <button
        type='button'
        onClick={() => setExpanded((prev) => !prev)}
        aria-expanded={expanded}
        aria-controls={listId}
        className='mt-2 flex items-center gap-1 text-[12px] font-medium text-muted-foreground sm:hidden'
      >
        Ver todo ({features.length})
        <ChevronDown
          aria-hidden='true'
          data-state={expanded ? 'open' : 'closed'}
          className='h-3.5 w-3.5 transition-transform data-[state=open]:rotate-180'
        />
      </button>

      <ul id={listId} className={cn('mt-3 grid gap-1.5 sm:grid-cols-2', !expanded && 'hidden sm:grid')}>
        {features.map((feature) => (
          <li key={feature} className='flex items-start gap-2 text-[12.5px]'>
            <Check aria-hidden='true' className='mt-0.5 h-3.5 w-3.5 shrink-0 text-primary' />
            <span className='text-foreground'>{feature}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}
