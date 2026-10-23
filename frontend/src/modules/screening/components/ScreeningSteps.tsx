import { cn } from '@/lib/utils'
import { SCREENING_STEPS } from '@/modules/screening/utils/statusCopy'
import { Check } from 'lucide-react'

interface ScreeningStepsProps {
  /** Index from `screeningStepIndex`; `SCREENING_STEPS.length` means all done. */
  current: number
}

/**
 * Where the evaluator is in the Prueba EDI: capture, review and score, then
 * close and schedule the next appointment. Read-only orientation, not navigation.
 */
export function ScreeningSteps({ current }: ScreeningStepsProps) {
  return (
    <nav aria-label='Pasos de la Prueba EDI'>
      <ol className='flex flex-wrap items-center gap-x-2 gap-y-1 text-sm'>
        {SCREENING_STEPS.map((label, index) => {
          const done = index < current
          const active = index === current
          return (
            <li key={label} className='flex items-center gap-2' aria-current={active ? 'step' : undefined}>
              {index > 0 && <span className='h-px w-4 bg-border sm:w-8' aria-hidden />}
              <span
                className={cn(
                  'flex size-6 shrink-0 items-center justify-center rounded-full border text-xs font-semibold tabular-nums',
                  done && 'border-primary bg-primary text-primary-foreground',
                  active && 'border-primary text-primary',
                  !done && !active && 'border-border text-muted-foreground'
                )}
                aria-hidden
              >
                {done ? <Check className='size-3.5' /> : index + 1}
              </span>
              <span className={cn(active ? 'font-semibold text-foreground' : 'text-muted-foreground')}>
                {label}
                {done && <span className='sr-only'> (hecho)</span>}
              </span>
            </li>
          )
        })}
      </ol>
    </nav>
  )
}
