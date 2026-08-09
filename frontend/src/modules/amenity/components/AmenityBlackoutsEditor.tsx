import { Button } from '@/components/ui/button'
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import {
  amenityBlackoutFormSchema,
  type AmenityBlackoutFormValues,
  type IAmenityBlackout,
} from '@/modules/amenity/schema'
import { AmenityBlackoutResource } from '@/modules/amenity/services/AmenityBlackoutResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { useForm } from 'react-hook-form'

const EMPTY_BLACKOUT: AmenityBlackoutFormValues = {
  start_date: '',
  end_date: '',
  reason: '',
}

/**
 * Bloqueos de fechas de una amenidad (mantenimiento, feriados, bloqueo admin) —
 * alta/baja en línea contra `/amenity_blackout`, refetch de la amenidad
 * (`blackouts`) tras cada mutación. Solo agrega/quita — no hay edición inline
 * (mismo alcance que pide la tarea: distinto de horarios, que sí editan).
 */
export function AmenityBlackoutsEditor({
  amenityId,
  blackouts,
  disabled,
}: {
  amenityId: string
  blackouts: IAmenityBlackout[]
  disabled: boolean
}) {
  const queryClient = useQueryClient()

  const {
    handleSubmit,
    reset,
    register,
    formState: { errors },
  } = useForm<AmenityBlackoutFormValues>({
    resolver: zodResolver(amenityBlackoutFormSchema),
    defaultValues: EMPTY_BLACKOUT,
  })

  const refresh = () =>
    queryClient.invalidateQueries({ queryKey: ['amenity', 'detail', amenityId] })

  const createMutation = useMutation({
    mutationFn: (values: AmenityBlackoutFormValues) =>
      AmenityBlackoutResource.create({ amenity_id: amenityId, ...values }),
    onSuccess: async () => {
      toast.success('Bloqueo agregado')
      reset(EMPTY_BLACKOUT)
      await refresh()
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo agregar el bloqueo'
      ),
  })

  const deleteMutation = useMutation({
    mutationFn: (id: string) => AmenityBlackoutResource.remove(id),
    onSuccess: async () => {
      toast.success('Bloqueo eliminado')
      await refresh()
    },
    onError: () => toast.error('No se pudo eliminar el bloqueo'),
  })

  const busy = disabled || createMutation.isPending || deleteMutation.isPending

  const sortedBlackouts = [...blackouts].sort((a, b) => a.start_date.localeCompare(b.start_date))

  return (
    <Field>
      <FieldLabel>Bloqueos de fechas</FieldLabel>
      <FieldDescription>
        Rangos en los que la amenidad NO se puede reservar (mantenimiento, feriados, bloqueo
        administrativo), independientes de &quot;fuera de servicio&quot;.
      </FieldDescription>

      {/* BUG FIX (E2E QA): this used to be a nested <form> inside AmenityForm's
          own <form> — invalid HTML that made the submit button fall back to
          the OUTER form's native GET submit (full page reload). Replaced with
          a <div> + an explicit handleSubmit() call wired to a type="button"
          click, keeping the exact same immediate-CRUD-with-refetch behavior. */}
      <div className='flex flex-wrap items-end gap-3 rounded-[10px] border border-border bg-[var(--surface-2)] p-3'>
        <div className='flex flex-col gap-1'>
          <label className='text-[12px] text-[var(--ink-3)]' htmlFor='blackout-start'>
            Desde
          </label>
          <Input
            id='blackout-start'
            type='date'
            className='w-[150px]'
            {...register('start_date')}
            disabled={busy}
          />
          <FieldError>{errors.start_date?.message}</FieldError>
        </div>
        <div className='flex flex-col gap-1'>
          <label className='text-[12px] text-[var(--ink-3)]' htmlFor='blackout-end'>
            Hasta
          </label>
          <Input
            id='blackout-end'
            type='date'
            className='w-[150px]'
            {...register('end_date')}
            disabled={busy}
          />
          <FieldError>{errors.end_date?.message}</FieldError>
        </div>
        <div className='flex flex-1 min-w-[180px] flex-col gap-1'>
          <label className='text-[12px] text-[var(--ink-3)]' htmlFor='blackout-reason'>
            Motivo (opcional)
          </label>
          <Input
            id='blackout-reason'
            placeholder='Mantenimiento programado'
            {...register('reason')}
            disabled={busy}
          />
        </div>
        <Button
          type='button'
          size='sm'
          variant='outline'
          disabled={busy}
          onClick={() => void handleSubmit((values) => createMutation.mutate(values))()}
        >
          <Plus className='size-4' />
          Agregar bloqueo
        </Button>
      </div>

      {sortedBlackouts.length > 0 ? (
        <ul className='space-y-1.5'>
          {sortedBlackouts.map((blackout) => (
            <li
              key={blackout.id}
              className='flex items-center justify-between gap-2 rounded-[10px] border border-border bg-card px-3 py-1.5 text-[12.5px]'
            >
              <span>
                {blackout.start_date === blackout.end_date
                  ? displayFormatDate(blackout.start_date, 'mediumDate')
                  : `${displayFormatDate(blackout.start_date, 'mediumDate')} – ${displayFormatDate(blackout.end_date, 'mediumDate')}`}
                {blackout.reason ? ` · ${blackout.reason}` : ''}
              </span>
              <button
                type='button'
                className='flex-none text-[var(--ink-4)] hover:text-destructive disabled:opacity-40'
                onClick={() => deleteMutation.mutate(blackout.id)}
                disabled={busy}
                aria-label='Quitar bloqueo'
              >
                <Trash2 className='size-4' />
              </button>
            </li>
          ))}
        </ul>
      ) : (
        <p className='text-[12px] text-[var(--ink-4)]'>Sin bloqueos configurados.</p>
      )}
    </Field>
  )
}
