import { Button } from '@/components/ui/button'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { NutritionalStatusBadge } from '@/modules/nutrition/components/NutritionalStatusBadge'
import { nutritionQueryKeys } from '@/modules/nutrition/hooks/useNutritionalAssessments'
import { useNutritionalClassifyPreview } from '@/modules/nutrition/hooks/useNutritionalClassifyPreview'
import {
  nutritionalAssessmentFormSchema,
  toNutritionalAssessmentPayload,
  type NutritionalAssessmentFormValues,
} from '@/modules/nutrition/schema'
import { NutritionalAssessmentResource } from '@/modules/nutrition/services/NutritionalAssessmentResource'
import { cmToMillimetres, kgToGrams } from '@/modules/nutrition/utils/nutritionalStatus'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { Modal } from '@/shared/components/Modal'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { toast } from '@/shared/hooks/useToast'
import { todayLocalDateString } from '@/shared/utils/dateHelper'
import { zodResolver } from '@hookform/resolvers/zod'
import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'

interface NutritionalAssessmentFormModalProps {
  isOpen: boolean
  childId: string
  onClose: () => void
}

/** Convierte el texto capturado a unidad menor sólo si está dentro del rango válido. */
function toMinorUnits(raw: string, min: number, max: number, convert: (_n: number) => number): number | null {
  const trimmed = raw.trim()
  if (trimmed === '') return null
  const value = Number(trimmed)
  if (Number.isNaN(value) || value < min || value > max) return null
  return convert(value)
}

/**
 * S5 — captura de peso y talla. La clasificación que se muestra mientras se
 * teclea la calcula el servidor (GET /nutritional_assessment/classify); al
 * guardar, el servidor vuelve a calcularla y es la única que se persiste.
 */
export function NutritionalAssessmentFormModal(props: NutritionalAssessmentFormModalProps) {
  if (!props.isOpen) return null
  return <NutritionalAssessmentFormSheet {...props} />
}

function NutritionalAssessmentFormSheet({ childId, onClose }: NutritionalAssessmentFormModalProps) {
  const queryClient = useQueryClient()
  const [isSaving, setIsSaving] = useState(false)

  const {
    register,
    handleSubmit,
    control,
    formState: { errors },
  } = useForm<NutritionalAssessmentFormValues>({
    resolver: zodResolver(nutritionalAssessmentFormSchema),
    defaultValues: { measured_on: todayLocalDateString(), weight_kg: '', height_cm: '', notes: '' },
  })

  const [measuredOn, weightKg, heightCm] = useWatch({ control, name: ['measured_on', 'weight_kg', 'height_cm'] })
  const preview = useNutritionalClassifyPreview({
    childId,
    measuredOn,
    weightGrams: toMinorUnits(weightKg, 0.5, 40, kgToGrams),
    heightMm: toMinorUnits(heightCm, 35, 140, cmToMillimetres),
  })

  const doSubmit = handleSubmit(async (values) => {
    setIsSaving(true)
    try {
      await NutritionalAssessmentResource.create(toNutritionalAssessmentPayload(childId, values))
      await queryClient.invalidateQueries({ queryKey: nutritionQueryKeys.child(childId) })
      toast.success('Medición registrada')
      onClose()
    } catch (err) {
      if (err instanceof ApiError) {
        const { title, description } = formatApiErrorMessage(err)
        toast.error(title, description)
      } else {
        toast.error('No se pudo registrar la medición')
      }
    } finally {
      setIsSaving(false)
    }
  })

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Registrar medición"
      size="lg"
      footer={
        <>
          <Button type="button" variant="outline" onClick={onClose} disabled={isSaving} className="min-w-[80px]">
            Cancelar
          </Button>
          <Button type="button" onClick={() => void doSubmit()} loading={isSaving} className="min-w-[100px]">
            Registrar
          </Button>
        </>
      }
    >
      <form
        onSubmit={(e) => {
          e.preventDefault()
          void doSubmit(e)
        }}
      >
        <RequiredFieldsLegend />
        <FieldGroup>
          <div className="grid grid-cols-12 gap-4">
            <Field className="col-span-12">
              <FieldLabel htmlFor="nutrition-measured_on" required>
                Fecha de medición
              </FieldLabel>
              <Input
                id="nutrition-measured_on"
                type="date"
                max={todayLocalDateString()}
                disabled={isSaving}
                {...register('measured_on')}
              />
              <FieldError>{errors.measured_on?.message}</FieldError>
            </Field>
            <Field className="col-span-12 sm:col-span-6">
              <FieldLabel htmlFor="nutrition-weight_kg">Peso (kg)</FieldLabel>
              <Input
                id="nutrition-weight_kg"
                type="number"
                inputMode="decimal"
                step="0.01"
                min="0.5"
                max="40"
                placeholder="Ej. 9.75"
                disabled={isSaving}
                {...register('weight_kg')}
              />
              <FieldError>{errors.weight_kg?.message}</FieldError>
            </Field>
            <Field className="col-span-12 sm:col-span-6">
              <FieldLabel htmlFor="nutrition-height_cm">Talla (cm)</FieldLabel>
              <Input
                id="nutrition-height_cm"
                type="number"
                inputMode="decimal"
                step="0.1"
                min="35"
                max="140"
                placeholder="Ej. 72.5"
                disabled={isSaving}
                {...register('height_cm')}
              />
              <FieldError>{errors.height_cm?.message}</FieldError>
            </Field>
            <Field className="col-span-12">
              <FieldLabel htmlFor="nutrition-notes">Notas</FieldLabel>
              <Textarea id="nutrition-notes" rows={3} disabled={isSaving} {...register('notes')} />
            </Field>
          </div>
        </FieldGroup>

        <section className="mt-6 space-y-3 border border-border p-4" aria-live="polite">
          <h4 className="text-sm font-semibold">Clasificación</h4>
          <PreviewBody preview={preview} />
        </section>
      </form>
    </Modal>
  )
}

function PreviewBody({ preview }: { preview: ReturnType<typeof useNutritionalClassifyPreview> }) {
  if (preview.isError) {
    return <p className="text-sm text-muted-foreground">Revisa la fecha y las medidas para ver la clasificación.</p>
  }
  if (!preview.data) {
    return <p className="text-sm text-muted-foreground">Captura el peso o la talla para ver la clasificación.</p>
  }

  const rows = [
    { label: 'Peso para la talla (P/T)', status: preview.data.weight_for_height_status },
    { label: 'Peso para la edad (P/E)', status: preview.data.weight_for_age_status },
    { label: 'Talla para la edad (T/E)', status: preview.data.height_for_age_status },
  ]

  return (
    <div className="space-y-2">
      <p className="text-xs text-muted-foreground">Edad a la medición: {preview.data.age_months} meses</p>
      {rows.map((row) => (
        <div key={row.label} className="flex flex-wrap items-center justify-between gap-2">
          <span className="text-sm">{row.label}</span>
          <NutritionalStatusBadge status={row.status} />
        </div>
      ))}
    </div>
  )
}
