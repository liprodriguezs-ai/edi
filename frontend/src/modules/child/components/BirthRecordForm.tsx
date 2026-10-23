import { Button } from '@/components/ui/button'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import {
  birthRecordFormSchema,
  booleanToTriState,
  triStateToBoolean,
  type BirthRecordFormValues,
  type IBirthRecord,
  type TriState,
} from '@/modules/child/schemas/birthRecordSchema'
import { BirthRecordResource } from '@/modules/child/services/BirthRecordResource'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useMemo, useState } from 'react'
import { Controller, useForm, type DefaultValues } from 'react-hook-form'
import { InlineLoading } from '@/shared/components/molecules/InlineLoading'

interface BirthRecordFormProps {
  childId: string
}

const NUMBER_FIELDS: Array<{
  name: keyof BirthRecordFormValues
  label: string
  min: number
  max: number
  colSpan?: 4 | 6 | 12
}> = [
  { name: 'gestational_age_weeks', label: 'Edad gestacional (semanas)', min: 20, max: 44, colSpan: 4 },
  { name: 'birth_weight_grams', label: 'Peso al nacer (g)', min: 400, max: 7000, colSpan: 4 },
  { name: 'birth_length_mm', label: 'Talla al nacer (mm)', min: 200, max: 650, colSpan: 4 },
  { name: 'prenatal_visit_count', label: 'Consultas prenatales', min: 0, max: 40, colSpan: 4 },
  { name: 'nicu_days', label: 'Días en UCIN', min: 0, max: 365, colSpan: 4 },
  { name: 'mother_age_at_birth_years', label: 'Edad de la madre al parto (años)', min: 10, max: 60, colSpan: 4 },
  { name: 'breastfeeding_months', label: 'Meses de lactancia', min: 0, max: 71, colSpan: 4 },
]

const TRI_STATE_FIELDS: Array<{ name: 'had_pregnancy_complication' | 'had_delivery_complication' | 'early_breastfeeding'; label: string }> = [
  { name: 'had_pregnancy_complication', label: 'Complicación durante el embarazo' },
  { name: 'had_delivery_complication', label: 'Complicación durante el parto' },
  { name: 'early_breastfeeding', label: 'Lactancia iniciada temprano' },
]

/**
 * REQ-CH-007 — antecedentes perinatales. Cada campo es NULLABLE y "se ignora"
 * (desconocido) MUST be distinguishable de false/0 (D7/D-14): los booleanos
 * usan un tri-state Sí/No/Se ignora, nunca un checkbox binario.
 */
export function BirthRecordForm({ childId }: BirthRecordFormProps) {
  const [record, setRecord] = useState<IBirthRecord | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [isSaving, setIsSaving] = useState(false)

  useEffect(() => {
    let cancelled = false
    setIsLoading(true)
    BirthRecordResource.search({ filters: [{ field: 'child_id', operator: '=', value: childId }] })
      .then((response) => {
        if (!cancelled) setRecord(response.data[0] ?? null)
      })
      .catch(() => {
        if (!cancelled) toast.error('No se pudieron cargar los antecedentes perinatales')
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [childId])

  const defaultValues = useMemo<BirthRecordFormValues>(
    () => ({
      child_id: childId,
      gestational_age_weeks: record?.gestational_age_weeks ?? null,
      birth_weight_grams: record?.birth_weight_grams ?? null,
      birth_length_mm: record?.birth_length_mm ?? null,
      prenatal_visit_count: record?.prenatal_visit_count ?? null,
      had_pregnancy_complication: record?.had_pregnancy_complication ?? null,
      had_delivery_complication: record?.had_delivery_complication ?? null,
      nicu_days: record?.nicu_days ?? null,
      mother_age_at_birth_years: record?.mother_age_at_birth_years ?? null,
      early_breastfeeding: record?.early_breastfeeding ?? null,
      breastfeeding_months: record?.breastfeeding_months ?? null,
    }),
    [childId, record]
  )

  const {
    register,
    handleSubmit,
    reset,
    control,
    formState: { errors },
  } = useForm<BirthRecordFormValues>({
    resolver: zodResolver(birthRecordFormSchema),
    defaultValues: defaultValues as DefaultValues<BirthRecordFormValues>,
  })

  useEffect(() => {
    reset(defaultValues)
  }, [reset, defaultValues])

  const doSubmit = handleSubmit(async (values) => {
    setIsSaving(true)
    try {
      const saved = record
        ? await BirthRecordResource.update(record.id, values)
        : await BirthRecordResource.create(values)
      setRecord(saved)
      toast.success('Antecedentes perinatales guardados')
    } catch (err) {
      if (err instanceof ApiError) {
        const { title, description } = formatApiErrorMessage(err)
        toast.error(title, description)
      } else {
        toast.error('No se pudieron guardar los antecedentes perinatales')
      }
    } finally {
      setIsSaving(false)
    }
  })

  if (isLoading) {
    return <InlineLoading name="birth-record-form" lines={4} />
  }

  return (
    <form onSubmit={(e) => void doSubmit(e)} className="space-y-6">
      <FieldGroup>
        <div className="grid grid-cols-12 gap-4">
          {NUMBER_FIELDS.map((f) => (
            <Field key={f.name} className={`col-span-12 ${f.colSpan === 4 ? 'md:col-span-4' : ''}`}>
              <FieldLabel htmlFor={`birth-${f.name}`}>{f.label}</FieldLabel>
              <Input
                id={`birth-${f.name}`}
                type="number"
                placeholder="Se ignora"
                disabled={isSaving}
                {...register(f.name, {
                  setValueAs: (v) => (v === '' || v === null || v === undefined ? null : Number(v)),
                })}
              />
              <FieldError>{(errors[f.name] as { message?: string } | undefined)?.message}</FieldError>
            </Field>
          ))}
        </div>
      </FieldGroup>

      <div className="space-y-4">
        {TRI_STATE_FIELDS.map((f) => (
          <Field key={f.name}>
            <FieldLabel>{f.label}</FieldLabel>
            <Controller
              name={f.name}
              control={control}
              render={({ field }) => (
                <RadioGroup
                  value={booleanToTriState(field.value)}
                  onValueChange={(v) => field.onChange(triStateToBoolean(v as TriState))}
                  className="flex flex-row gap-6"
                  disabled={isSaving}
                >
                  <div className="flex items-center gap-2">
                    <RadioGroupItem value="yes" id={`${f.name}-yes`} />
                    <Label htmlFor={`${f.name}-yes`}>Sí</Label>
                  </div>
                  <div className="flex items-center gap-2">
                    <RadioGroupItem value="no" id={`${f.name}-no`} />
                    <Label htmlFor={`${f.name}-no`}>No</Label>
                  </div>
                  <div className="flex items-center gap-2">
                    <RadioGroupItem value="unknown" id={`${f.name}-unknown`} />
                    <Label htmlFor={`${f.name}-unknown`}>Se ignora</Label>
                  </div>
                </RadioGroup>
              )}
            />
          </Field>
        ))}
      </div>

      <div className="flex justify-end">
        <Button type="submit" loading={isSaving}>
          {isSaving ? 'Guardando…' : record ? 'Actualizar' : 'Guardar'}
        </Button>
      </div>
    </form>
  )
}
