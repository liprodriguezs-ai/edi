import { Button } from '@/components/ui/button'
import { Calendar } from '@/components/ui/calendar'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { cn } from '@/lib/utils'
import { format, isValid, parse } from 'date-fns'
import { es } from 'date-fns/locale'
import { CalendarIcon, X } from 'lucide-react'
import { useState } from 'react'

interface DatePickerProps {
  value?: string
  clearable?: boolean
  onChange: (_date: string) => void
  placeholder?: string
  disabled?: boolean
  id?: string
  className?: string
  fromYear?: number
  toYear?: number
}

export function DatePicker({
  value,
  clearable = true,
  onChange,
  placeholder = 'Seleccionar fecha',
  disabled = false,
  id,
  className,
  fromYear = 1940,
  toYear = new Date().getFullYear() + 5,
}: DatePickerProps) {
  const [open, setOpen] = useState(false)

  const date = value ? parse(value, 'yyyy-MM-dd', new Date()) : undefined
  const selectedDate = date && isValid(date) ? date : undefined

  const handleSelect = (selected: Date | undefined) => {
    onChange(selected ? format(selected, 'yyyy-MM-dd') : '')
    setOpen(false)
  }

  const showClear = clearable && selectedDate && !disabled

  return (
    <div className="relative w-full">
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            id={id}
            type="button"
            variant="outline"
            disabled={disabled}
            className={cn(
              'w-full justify-start text-left font-normal',
              !selectedDate && 'text-muted-foreground',
              showClear && 'pr-8',
              className,
            )}
          >
            <CalendarIcon className="mr-2 h-4 w-4" />
            {selectedDate ? format(selectedDate, 'dd/MM/yyyy') : placeholder}
          </Button>
        </PopoverTrigger>
        <PopoverContent className="w-auto overflow-hidden p-0" align="start">
          <Calendar
            mode="single"
            selected={selectedDate}
            defaultMonth={selectedDate}
            onSelect={handleSelect}
            captionLayout="dropdown"
            locale={es}
            fromYear={fromYear}
            toYear={toYear}
          />
        </PopoverContent>
      </Popover>

      {showClear && (
        <button
          type="button"
          aria-label="Limpiar fecha"
          className="absolute right-2 top-1/2 -translate-y-1/2 flex h-6 w-6 items-center justify-center rounded text-muted-foreground hover:text-foreground hover:bg-accent transition-colors"
          onClick={(e) => {
            e.preventDefault()
            e.stopPropagation()
            handleSelect(undefined)
          }}
        >
          <X className="h-3.5 w-3.5" />
        </button>
      )}
    </div>
  )
}
