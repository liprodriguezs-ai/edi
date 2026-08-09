import { Button } from '@/components/ui/button'
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  WEEKDAY_LABELS,
  amenitySlotFormSchema,
  type AmenitySlotFormValues,
  type IAmenitySlot,
} from '@/modules/amenity/schema'
import { AmenitySlotResource } from '@/modules/amenity/services/AmenitySlotResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import { formatTimeHHmm } from '@/shared/utils/dateHelper'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, Trash2, X } from 'lucide-react'
import { useState } from 'react'
import { Controller, useForm } from 'react-hook-form'

const EMPTY_SLOT: AmenitySlotFormValues = {
  weekday: 0,
  start_time: '',
  end_time: '',
  capacity_override: null,
}

/**
 * Horarios fijos de una amenidad en modo `by_slot` — CRUD en línea contra
 * `/amenity_slot`, refetch de la amenidad (`slots`) tras cada mutación. Un solo
 * formulario sirve para alta y edición (`editingId` controla el modo).
 */
export function AmenitySlotsEditor({
  amenityId,
  slots,
  disabled,
}: {
  amenityId: string
  slots: IAmenitySlot[]
  disabled: boolean
}) {
  const queryClient = useQueryClient()
  const [editingId, setEditingId] = useState<string | null>(null)

  const {
    handleSubmit,
    reset,
    control,
    register,
    formState: { errors },
  } = useForm<AmenitySlotFormValues>({
    resolver: zodResolver(amenitySlotFormSchema),
    defaultValues: EMPTY_SLOT,
  })

  const refresh = () =>
    queryClient.invalidateQueries({ queryKey: ['amenity', 'detail', amenityId] })

  const saveMutation = useMutation({
    mutationFn: async (values: AmenitySlotFormValues) => {
      const payload = { amenity_id: amenityId, ...values }
      if (editingId) {
        return AmenitySlotResource.update(editingId, payload)
      }
      return AmenitySlotResource.create(payload)
    },
    onSuccess: async () => {
      toast.success(editingId ? 'Horario actualizado' : 'Horario agregado')
      setEditingId(null)
      reset(EMPTY_SLOT)
      await refresh()
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo guardar el horario'
      ),
  })

  const deleteMutation = useMutation({
    mutationFn: (id: string) => AmenitySlotResource.remove(id),
    onSuccess: async () => {
      toast.success('Horario eliminado')
      await refresh()
    },
    onError: () => toast.error('No se pudo eliminar el horario'),
  })

  const busy = disabled || saveMutation.isPending || deleteMutation.isPending

  const startEdit = (slot: IAmenitySlot) => {
    setEditingId(slot.id)
    reset({
      weekday: slot.weekday,
      start_time: formatTimeHHmm(slot.start_time),
      end_time: formatTimeHHmm(slot.end_time),
      capacity_override: slot.capacity_override ?? null,
    })
  }

  const cancelEdit = () => {
    setEditingId(null)
    reset(EMPTY_SLOT)
  }

  const sortedSlots = [...slots].sort(
    (a, b) => a.weekday - b.weekday || a.start_time.localeCompare(b.start_time)
  )

  return (
    <Field>
      <FieldLabel>Horarios fijos</FieldLabel>
      <FieldDescription>
        Franjas horarias reservables (modo &quot;por horario&quot;). Cada horario aplica a un día de
        la semana.
      </FieldDescription>

      {/* BUG FIX (E2E QA): this used to be a nested <form> inside AmenityForm's
          own <form> — invalid HTML that made the submit button fall back to
          the OUTER form's native GET submit (full page reload). Replaced with
          a <div> + an explicit handleSubmit() call wired to a type="button"
          click, keeping the exact same immediate-CRUD-with-refetch behavior. */}
      <div className='flex flex-wrap items-end gap-3 rounded-[10px] border border-border bg-[var(--surface-2)] p-3'>
        <div className='flex flex-col gap-1'>
          <label className='text-[12px] text-[var(--ink-3)]' htmlFor='slot-weekday'>
            Día
          </label>
          <Controller
            name='weekday'
            control={control}
            render={({ field }) => (
              <Select
                value={String(field.value)}
                onValueChange={(v) => field.onChange(Number(v))}
                disabled={busy}
              >
                <SelectTrigger id='slot-weekday' className='w-[110px]'>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {WEEKDAY_LABELS.map((label, index) => (
                    <SelectItem key={label} value={String(index)}>
                      {label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          />
        </div>
        <div className='flex flex-col gap-1'>
          <label className='text-[12px] text-[var(--ink-3)]' htmlFor='slot-start'>
            Inicio
          </label>
          <Input
            id='slot-start'
            type='time'
            className='w-[110px]'
            {...register('start_time')}
            disabled={busy}
          />
          <FieldError>{errors.start_time?.message}</FieldError>
        </div>
        <div className='flex flex-col gap-1'>
          <label className='text-[12px] text-[var(--ink-3)]' htmlFor='slot-end'>
            Fin
          </label>
          <Input
            id='slot-end'
            type='time'
            className='w-[110px]'
            {...register('end_time')}
            disabled={busy}
          />
          <FieldError>{errors.end_time?.message}</FieldError>
        </div>
        <div className='flex flex-col gap-1'>
          <label className='text-[12px] text-[var(--ink-3)]' htmlFor='slot-capacity'>
            Cupo (opcional)
          </label>
          <Input
            id='slot-capacity'
            type='number'
            min={1}
            className='w-[110px]'
            placeholder='Default'
            {...register('capacity_override', {
              setValueAs: (v) => (v === '' || v == null ? null : Number(v)),
            })}
            disabled={busy}
          />
        </div>
        <div className='flex items-center gap-2'>
          <Button
            type='button'
            size='sm'
            variant={editingId ? 'default' : 'outline'}
            disabled={busy}
            onClick={() => void handleSubmit((values) => saveMutation.mutate(values))()}
          >
            {editingId ? (
              'Guardar cambios'
            ) : (
              <>
                <Plus className='size-4' />
                Agregar horario
              </>
            )}
          </Button>
          {editingId && (
            <Button type='button' variant='outline' size='sm' onClick={cancelEdit} disabled={busy}>
              <X className='size-4' />
              Cancelar
            </Button>
          )}
        </div>
      </div>

      {sortedSlots.length > 0 ? (
        <ul className='space-y-1.5'>
          {sortedSlots.map((slot) => (
            <li
              key={slot.id}
              className='flex items-center justify-between gap-2 rounded-[10px] border border-border bg-card px-3 py-1.5 text-[12.5px]'
            >
              <span>
                {WEEKDAY_LABELS[slot.weekday]} · {formatTimeHHmm(slot.start_time)}–
                {formatTimeHHmm(slot.end_time)}
                {slot.capacity_override ? ` · cupo ${slot.capacity_override}` : ''}
              </span>
              <div className='flex items-center gap-1'>
                <button
                  type='button'
                  className='flex-none text-[var(--ink-4)] hover:text-primary disabled:opacity-40'
                  onClick={() => startEdit(slot)}
                  disabled={busy}
                  aria-label='Editar horario'
                >
                  <Pencil className='size-4' />
                </button>
                <button
                  type='button'
                  className='flex-none text-[var(--ink-4)] hover:text-destructive disabled:opacity-40'
                  onClick={() => deleteMutation.mutate(slot.id)}
                  disabled={busy}
                  aria-label='Quitar horario'
                >
                  <Trash2 className='size-4' />
                </button>
              </div>
            </li>
          ))}
        </ul>
      ) : (
        <p className='text-[12px] text-[var(--ink-4)]'>Sin horarios configurados.</p>
      )}
    </Field>
  )
}
