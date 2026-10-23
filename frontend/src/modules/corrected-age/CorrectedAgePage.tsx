import { Button } from '@/components/ui/button'
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import {
  correctedAgeFormSchema,
  formatAgeBreakdown,
  notApplicableReasonCopy,
  readyFromCopy,
  type CorrectedAgeFormValues,
  type CorrectedAgeGroup,
  type CorrectedAgeResult,
} from '@/modules/corrected-age/schema'
import { CorrectedAgeResource } from '@/modules/corrected-age/services/CorrectedAgeResource'
import { formatApiErrorMessage, ApiError } from '@/shared/api/apiClient'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { SectionLabel } from '@/shared/components/atoms/SectionLabel'
import { DescriptionItem, DescriptionList } from '@/shared/components/molecules/DescriptionList'
import { Panel } from '@/shared/components/molecules/Panel'
import { DatePicker } from '@/shared/components/DatePicker'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { Controller, useForm } from 'react-hook-form'

function groupLabel(group: CorrectedAgeGroup | null): string {
  if (!group) return 'Fuera del rango de la Prueba EDI'
  return `Grupo ${group.ordinal}`
}

/**
 * S6 — the legacy "Calcular edad corregida" screen. The server applies the same
 * rule as the Prueba EDI (< 37 semanas y < 24 meses); this page only collects
 * the input and explains the result.
 */
export function CorrectedAgePage() {
  const [result, setResult] = useState<CorrectedAgeResult | null>(null)
  const [isCalculating, setIsCalculating] = useState(false)

  const {
    control,
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<CorrectedAgeFormValues>({
    resolver: zodResolver(correctedAgeFormSchema),
    defaultValues: { birth_date: '', reference_date: '' },
  })

  const onSubmit = async (values: CorrectedAgeFormValues) => {
    setIsCalculating(true)
    try {
      setResult(await CorrectedAgeResource.calculate(values))
    } catch (err) {
      if (err instanceof ApiError) {
        const { title, description } = formatApiErrorMessage(err)
        toast.error(title, description)
      } else {
        toast.error('No se pudo calcular la edad corregida')
      }
    } finally {
      setIsCalculating(false)
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Edad corregida"
        description="Calcula la edad corregida por prematurez con la misma regla que aplica la Prueba EDI."
      />

      <Panel as="div">
        <form onSubmit={handleSubmit(onSubmit)} noValidate>
          <div className="grid grid-cols-12 gap-4">
            <Field className="col-span-12 sm:col-span-4">
              <FieldLabel htmlFor="corrected-age-birth-date" required>
                Fecha de nacimiento
              </FieldLabel>
              <Controller
                name="birth_date"
                control={control}
                render={({ field }) => (
                  <DatePicker
                    id="corrected-age-birth-date"
                    value={field.value}
                    onChange={field.onChange}
                    clearable={false}
                    disabled={isCalculating}
                    placeholder="Elige la fecha"
                  />
                )}
              />
              <FieldError>{errors.birth_date?.message}</FieldError>
            </Field>

            <Field className="col-span-12 sm:col-span-4">
              <FieldLabel htmlFor="corrected-age-weeks" required>
                Semanas de gestación
              </FieldLabel>
              <Input
                id="corrected-age-weeks"
                type="number"
                inputMode="numeric"
                min={20}
                max={44}
                disabled={isCalculating}
                {...register('gestational_weeks', { valueAsNumber: true })}
              />
              <FieldError>{errors.gestational_weeks?.message}</FieldError>
            </Field>

            <Field className="col-span-12 sm:col-span-4">
              <FieldLabel htmlFor="corrected-age-reference-date">Fecha de referencia</FieldLabel>
              <Controller
                name="reference_date"
                control={control}
                render={({ field }) => (
                  <DatePicker
                    id="corrected-age-reference-date"
                    value={field.value}
                    onChange={field.onChange}
                    disabled={isCalculating}
                    placeholder="Hoy"
                  />
                )}
              />
              <FieldDescription>Si la dejas vacía se usa la fecha de hoy.</FieldDescription>
            </Field>
          </div>

          <div className="mt-4 flex justify-end">
            <Button type="submit" loading={isCalculating}>
              {isCalculating ? 'Calculando…' : 'Calcular'}
            </Button>
          </div>
        </form>
      </Panel>

      {result && <CorrectedAgeResultCard result={result} />}

      <section className="max-w-prose space-y-2 text-sm">
        <h2 className="text-base font-semibold">¿Qué es la edad corregida?</h2>
        <p>
          Cuando el niño es menor de 2 años y nació antes de las 37 semanas de gestación es necesario realizar un
          “ajuste por prematurez”: se calcula el número de semanas que le faltaron para cumplir las 40 semanas de
          gestación (las que la Prueba EDI considera un embarazo de término) y se restan a la edad cronológica.
        </p>
        <p>
          Al resultado del ajuste se le conoce como <strong>edad corregida</strong>.
        </p>
        <p className="text-xs text-muted-foreground">
          Fuente: Comisión Nacional de Protección Social en Salud. Evaluación del Desarrollo Infantil Prueba EDI 2a.
          Edición, Manual de Aplicación. Ciudad de México, Secretaría de Salud, 2016, pág. 146.
        </p>
      </section>
    </div>
  )
}

function CorrectedAgeResultCard({ result }: { result: CorrectedAgeResult }) {
  return (
    <div aria-live="polite">
      <Panel>
        <SectionLabel as="h2" className="mb-3">
          Resultado al {displayFormatDate(result.reference_date, 'mediumDate')}
        </SectionLabel>
        <DescriptionList columns={2} variant="plain">
          <DescriptionItem
            label="Edad cronológica"
            value={
              <>
                <span className="block text-lg font-semibold">{formatAgeBreakdown(result.chronological)}</span>
                <span className="block text-xs text-muted-foreground">
                  {result.chronological.days} días · {groupLabel(result.chronological_age_group)}
                </span>
              </>
            }
          />
          <DescriptionItem
            label="Edad corregida"
            value={
              result.corrected ? (
                <>
                  <span className="block text-lg font-semibold" data-testid="corrected-age">
                    {formatAgeBreakdown(result.corrected)}
                  </span>
                  <span className="block text-xs text-muted-foreground">
                    {result.corrected.days} días · {groupLabel(result.corrected_age_group)}
                  </span>
                  {result.ready_from && (
                    <span className="mt-1 block text-sm font-medium" data-testid="corrected-age-ready-from">
                      {readyFromCopy(result.ready_from)}
                    </span>
                  )}
                </>
              ) : (
                notApplicableReasonCopy(result.reason)
              )
            }
          />
        </DescriptionList>
      </Panel>
    </div>
  )
}
