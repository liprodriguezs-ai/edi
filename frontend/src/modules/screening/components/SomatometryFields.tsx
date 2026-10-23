import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import type { IScreening } from '@/modules/screening/schema'
import { isAgeGroupChosen } from '@/modules/screening/utils/ageGroupCopy'
import {
  fromStoredUnit,
  toStoredUnit,
  type SomatometryDisplayUnit,
} from '@/modules/screening/utils/somatometryUnits'
import { screeningErrorMessage } from '@/modules/screening/utils/screeningErrors'
import { toast } from '@/shared/hooks/useToast'
import { useRef, useState } from 'react'

interface SomatometryFieldsProps {
  screening: IScreening
  onSave: (_values: {
    weight_grams?: number | null
    height_mm?: number | null
    head_circumference_mm?: number | null
  }) => Promise<void>
  disabled?: boolean
}

const DEBOUNCE_MS = 750

type SomatometryKey = 'weight_grams' | 'height_mm' | 'head_circumference_mm'

/**
 * Typed in kg/cm, stored in grams/millimetres (hard rule 7). Ranges mirror the
 * server's plausibility validation (ScreeningCreate/UpdateRequest).
 */
const FIELDS: ReadonlyArray<{
  key: SomatometryKey
  id: string
  label: string
  unit: SomatometryDisplayUnit
  range: string
  placeholder: string
}> = [
  { key: 'weight_grams', id: 'somatometry-weight', label: 'Peso (kg)', unit: 'kg', range: 'Entre 1.5 y 40 kg', placeholder: '8.2' },
  { key: 'height_mm', id: 'somatometry-height', label: 'Talla (cm)', unit: 'cm', range: 'Entre 35 y 140 cm', placeholder: '70.5' },
  {
    key: 'head_circumference_mm',
    id: 'somatometry-hc',
    label: 'Perímetro cefálico (cm)',
    unit: 'cm',
    range: 'Entre 25 y 70 cm',
    placeholder: '44',
  },
]

/**
 * D14 — debounced Orion `update`; a successful save re-fetches item bank
 * (locked flags depend on it).
 *
 * The parent MUST render this with `key={screening.id}` so React remounts
 * (fresh local state) if the underlying screening ever changes, instead of
 * syncing local state from a prop via an effect (React anti-pattern).
 */
export function SomatometryFields({ screening, onSave, disabled = false }: SomatometryFieldsProps) {
  const [values, setValues] = useState<Record<SomatometryKey, string>>(() => ({
    weight_grams: fromStoredUnit(screening.weight_grams, 'kg'),
    height_mm: fromStoredUnit(screening.height_mm, 'cm'),
    head_circumference_mm: fromStoredUnit(screening.head_circumference_mm, 'cm'),
  }))
  const [errors, setErrors] = useState<Record<string, string>>({})
  const timerRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)

  const scheduleSave = (next: Record<SomatometryKey, string>) => {
    if (timerRef.current) clearTimeout(timerRef.current)
    timerRef.current = setTimeout(() => {
      void onSave({
        weight_grams: toStoredUnit(next.weight_grams, 'kg'),
        height_mm: toStoredUnit(next.height_mm, 'cm'),
        head_circumference_mm: toStoredUnit(next.head_circumference_mm, 'cm'),
      })
        .then(() => setErrors({}))
        .catch((err: unknown) => {
          toast.error(screeningErrorMessage(err, 'No se pudo guardar la somatometría'))
          setErrors(extractFieldErrors(err))
        })
    }, DEBOUNCE_MS)
  }

  return (
    <section className="grid grid-cols-1 gap-4 sm:grid-cols-3">
      {FIELDS.map((field) => (
        <Field key={field.key}>
          <FieldLabel htmlFor={field.id}>{field.label}</FieldLabel>
          <Input
            id={field.id}
            type="text"
            inputMode="decimal"
            placeholder={`Ej. ${field.placeholder}`}
            className="font-mono tabular-nums"
            disabled={disabled}
            value={values[field.key]}
            onChange={(e) => {
              const next = { ...values, [field.key]: e.target.value }
              setValues(next)
              scheduleSave(next)
            }}
          />
          <FieldDescription>{field.range}</FieldDescription>
          {field.key === 'head_circumference_mm' && isAgeGroupChosen(screening) && (
            <FieldDescription>
              En la Prueba EDI a elección, el reactivo de perímetro cefálico se contesta a mano.
            </FieldDescription>
          )}
          <FieldError>{errors[field.key]}</FieldError>
        </Field>
      ))}
    </section>
  )
}

function extractFieldErrors(err: unknown): Record<string, string> {
  if (!err || typeof err !== 'object') return {}
  const payload = (err as { payload?: { errors?: Record<string, string[]> } }).payload
  if (!payload?.errors) return {}
  const result: Record<string, string> = {}
  for (const [field, messages] of Object.entries(payload.errors)) {
    result[field] = messages[0]
  }
  return result
}
