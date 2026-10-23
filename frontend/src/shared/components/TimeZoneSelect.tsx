import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { MEXICO_TIME_ZONES, type ITimeZoneOption } from '@/shared/constants/timeZones'

interface TimeZoneSelectProps {
  id?: string
  value: string | undefined
  onChange: (_value: string) => void
  disabled?: boolean
}

/**
 * Select de zona horaria IANA del tenant. Si el valor guardado no está en la
 * lista oficial (ej. un tenant legado en `UTC`), se agrega como opción para no
 * perderlo al editar.
 */
export function TimeZoneSelect({ id, value, onChange, disabled }: TimeZoneSelectProps) {
  const options: readonly ITimeZoneOption[] =
    value && !MEXICO_TIME_ZONES.some((option) => option.value === value)
      ? [...MEXICO_TIME_ZONES, { value, label: value }]
      : MEXICO_TIME_ZONES

  return (
    <Select value={value || undefined} onValueChange={onChange} disabled={disabled}>
      <SelectTrigger id={id} className='w-full'>
        <SelectValue placeholder='Seleccionar zona horaria...' />
      </SelectTrigger>
      <SelectContent>
        {options.map((option) => (
          <SelectItem key={option.value} value={option.value}>
            {option.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}
