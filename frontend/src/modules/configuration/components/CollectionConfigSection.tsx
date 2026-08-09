import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Field, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { PERIODICITY } from '@/modules/chargeConcept/schema'
import { useSectionEdits } from '@/modules/configuration/hooks/useSectionEdits'
import {
  ConfigurationResource,
  type CollectionSettings,
} from '@/modules/configuration/services/ConfigurationResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { ChargeLifecycleTimeline } from '@/shared/components/molecules/ChargeLifecycleTimeline'
import { toast } from '@/shared/hooks/useToast'
import { computeLifecycleMilestones, resolveTimingSettings } from '@/shared/utils/chargeLifecycle'
import { useMutation, useQueryClient, type UseMutationResult } from '@tanstack/react-query'
import { CircleHelp } from 'lucide-react'
import { useMemo, useState } from 'react'

const CARD = 'rounded-[14px] border border-border bg-card p-5 shadow-sm'
const COLLECTION_PATH = '/config/collection-settings'

interface Props {
  collection: CollectionSettings | undefined
  canUpdate: boolean
  /** Mutación compartida de configuración (se conserva por compatibilidad de la interfaz). */
  saveMutation: UseMutationResult<
    unknown,
    Error,
    { path: string; payload: Record<string, unknown> }
  >
}

export function CollectionConfigSection({ collection, canUpdate }: Props) {
  const { form, edits, setField } = useSectionEdits<CollectionSettings>(collection)
  const queryClient = useQueryClient()
  // Cambios pendientes de doble confirmación (ej. activar datos bancarios visibles).
  const [pendingEdits, setPendingEdits] = useState<Record<string, unknown> | null>(null)

  // Guardado propio (no el compartido) para poder interceptar el 422 requires_confirmation
  // sin mostrar el string crudo del backend ni perder los cambios de timing.
  const collectionSave = useMutation<
    unknown,
    ApiError,
    { payload: Record<string, unknown>; doubleConfirm: boolean }
  >({
    mutationFn: ({ payload, doubleConfirm }) =>
      ConfigurationResource.save(
        COLLECTION_PATH,
        doubleConfirm ? { ...payload, double_confirm: true } : payload
      ),
    onSuccess: () => {
      toast.success('Configuración guardada')
      setPendingEdits(null)
      void queryClient.invalidateQueries({ queryKey: ['config'] })
    },
    onError: (error, variables) => {
      // 422 con requires_confirmation → pedir confirmación al usuario y reenviar con double_confirm.
      if (
        error.status === 422 &&
        error.payload?.requires_confirmation &&
        !variables.doubleConfirm
      ) {
        setPendingEdits(variables.payload)
        return
      }
      const { title } = formatApiErrorMessage(error)
      toast.error(title)
    },
  })

  const saveCollection = () => {
    collectionSave.mutate({ payload: edits, doubleConfirm: false })
  }

  const confirmDoubleSave = () => {
    if (pendingEdits) collectionSave.mutate({ payload: pendingEdits, doubleConfirm: true })
  }

  const isPending = collectionSave.isPending

  // Preview en vivo desde los valores SIN guardar (overlay de useSectionEdits).
  const timing = useMemo(() => resolveTimingSettings(form), [form])
  const previewMilestones = useMemo(
    () =>
      computeLifecycleMilestones({
        periodicityId: PERIODICITY.MONTHLY,
        generationDay: timing.generationDay,
        dueSoonDays: timing.dueSoonDays,
        graceDays: timing.graceDays,
      }),
    [timing]
  )

  const numericSetting = (
    key: string,
    label: string,
    tooltip: string,
    min: number,
    max: number
  ) => (
    <Field className='col-span-12 md:col-span-6'>
      <FieldLabel htmlFor={`cfg-${key}`} className='flex items-center gap-1.5'>
        {label}
        <Tooltip>
          <TooltipTrigger asChild>
            <button
              type='button'
              aria-label={`Ayuda: ${label}`}
              className='text-[var(--ink-3)] hover:text-foreground'
            >
              <CircleHelp className='size-3.5' aria-hidden />
            </button>
          </TooltipTrigger>
          <TooltipContent className='max-w-[260px]'>{tooltip}</TooltipContent>
        </Tooltip>
      </FieldLabel>
      <Input
        id={`cfg-${key}`}
        type='number'
        min={min}
        max={max}
        value={form[key] != null ? String(form[key]) : ''}
        onChange={(e) =>
          setField(
            key as keyof CollectionSettings,
            e.target.value === '' ? undefined : Number(e.target.value)
          )
        }
        disabled={!canUpdate || isPending}
      />
    </Field>
  )

  return (
    <div className={CARD}>
      <FieldGroup className='gap-6'>
        <div>
          <p className='mb-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
            Ciclo de cobro
          </p>
          <div className='grid grid-cols-12 gap-4'>
            {numericSetting(
              'collections.charge_generation_day',
              'Día de generación',
              'Qué día del período se crea el cargo (1 = primer día).',
              1,
              28
            )}
            {numericSetting(
              'collections.due_soon_days',
              'Aviso por vencer',
              'Cuántos días antes del vencimiento se marca como «por vencer» y se manda recordatorio.',
              0,
              60
            )}
            {numericSetting(
              'collections.grace_days',
              'Días de gracia',
              'Días después del vencimiento antes de marcar el cargo como moroso. Cada programación de cobro puede sobreescribirlo.',
              0,
              60
            )}
          </div>
        </div>

        <div className='rounded-[14px] border border-border bg-[var(--surface-2)] p-4'>
          <p className='mb-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
            Vista previa (ejemplo mensual)
          </p>
          <ChargeLifecycleTimeline mode='parametric' milestones={previewMilestones} />
          <p className='mt-3 text-[11px] text-[var(--ink-3)]'>
            Los días de gracia usan este valor del condominio; cada programación de cobro puede
            sobreescribirlo.
          </p>
        </div>

        <Field>
          <label className='flex cursor-pointer items-center gap-2 text-sm font-medium'>
            <Checkbox
              checked={Boolean(form['collections.bank_data_visible_to_residents'])}
              onCheckedChange={(checked) =>
                setField(
                  'collections.bank_data_visible_to_residents' as keyof CollectionSettings,
                  checked === true
                )
              }
              disabled={!canUpdate || isPending}
            />
            Datos bancarios visibles a residentes
          </label>
          <FieldDescription>Muestra CLABE/cuenta en la app del residente</FieldDescription>
        </Field>

        {canUpdate && (
          <div className='flex justify-end border-t border-[var(--line-2)] pt-4'>
            <Button
              onClick={saveCollection}
              disabled={isPending}
              className='min-w-[120px] rounded-full'
            >
              {isPending ? 'Guardando…' : 'Guardar'}
            </Button>
          </div>
        )}
      </FieldGroup>

      <ConfirmDialog
        isOpen={pendingEdits !== null}
        title='Confirmar cambio sensible'
        description='Hacer visibles los datos bancarios a los residentes es un cambio sensible. ¿Deseas continuar?'
        confirmLabel='Sí, continuar'
        cancelLabel='Cancelar'
        isLoading={isPending}
        onConfirm={confirmDoubleSave}
        onCancel={() => setPendingEdits(null)}
      />
    </div>
  )
}
