import { Input } from '@/components/ui/input'
import { X } from 'lucide-react'

interface RangeFilterProps {
  label: string
  min: number | null
  max: number | null
  onChange: (next: { min: number | null; max: number | null }) => void
  placeholder?: { min: string; max: string }
  step?: number
}

export function RangeFilter({
  label,
  min,
  max,
  onChange,
  placeholder = { min: 'Min', max: 'Max' },
  step = 1,
}: RangeFilterProps) {
  const hasValue = min !== null || max !== null
  return (
    <div className='flex w-full flex-col gap-1 sm:w-48'>
      <div className='flex items-center justify-between gap-2'>
        <span className='text-xs text-muted-foreground font-medium'>{label}</span>
        {hasValue && (
          <button
            type='button'
            onClick={() => onChange({ min: null, max: null })}
            className='rounded p-0.5 text-muted-foreground hover:bg-accent hover:text-foreground'
            aria-label='Limpiar filtro'
          >
            <X className='h-3.5 w-3.5' />
          </button>
        )}
      </div>
      <div className='flex items-center gap-1'>
        <Input
          type='number'
          inputMode='numeric'
          step={step}
          placeholder={placeholder.min}
          value={min ?? ''}
          onChange={(e) =>
            onChange({
              min: e.target.value === '' ? null : Number(e.target.value),
              max,
            })
          }
          className='h-9 bg-card text-sm'
        />
        <span className='text-muted-foreground text-xs'>–</span>
        <Input
          type='number'
          inputMode='numeric'
          step={step}
          placeholder={placeholder.max}
          value={max ?? ''}
          onChange={(e) =>
            onChange({
              min,
              max: e.target.value === '' ? null : Number(e.target.value),
            })
          }
          className='h-9 bg-card text-sm'
        />
      </div>
    </div>
  )
}
