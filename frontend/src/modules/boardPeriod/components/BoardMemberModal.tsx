import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import {
  makeBoardMemberSchema,
  type BoardMemberValues,
  type IBoardMember,
  type IBoardPeriod,
} from '@/modules/boardPeriod/schema'
import { BoardMemberResource } from '@/modules/boardPeriod/services/BoardMemberResource'
import { UserCombobox } from '@/modules/unit/components/UserCombobox'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { useMemo } from 'react'
import { Controller, useForm } from 'react-hook-form'

interface BoardMemberModalProps {
  isOpen: boolean
  period: IBoardPeriod
  member?: IBoardMember | null
  onClose: () => void
  onSaved: () => void
}

interface BoardMemberFormProps {
  period: IBoardPeriod
  member: IBoardMember | null
  onClose: () => void
  onSaved: () => void
}

/**
 * Alta/edición de un miembro de la mesa vigente. El cuerpo (con `useCatalogs`
 * del catálogo de cargos) vive en el hijo `BoardMemberForm`, que sólo se monta
 * cuando el diálogo está abierto: así entrar a la lista NO dispara el `/selects`
 * de cargos (regla de infra §2.12 — carga al abrir el form).
 */
export function BoardMemberModal({
  isOpen,
  period,
  member,
  onClose,
  onSaved,
}: BoardMemberModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className='p-0 max-w-lg' aria-describedby={undefined}>
        <BoardMemberForm
          period={period}
          member={member ?? null}
          onClose={onClose}
          onSaved={onSaved}
        />
      </DialogContent>
    </Dialog>
  )
}

function BoardMemberForm({ period, member, onClose, onSaved }: BoardMemberFormProps) {
  const isEdit = Boolean(member)

  // Invariantes §7.5 (persona única) y §7.3.2 (cargos obligatorios al editar)
  // viven en el schema; recibe el contexto del periodo.
  const schema = useMemo(
    () =>
      makeBoardMemberSchema({ members: period.members ?? [], editingMemberId: member?.id ?? null }),
    [period.members, member?.id]
  )

  const defaultValues = useMemo<BoardMemberValues>(
    () => ({
      user_id: member?.user_id ?? '',
      board_role_id: member?.board_role_id ?? '',
      user_name: member?.user?.name ?? '',
    }),
    [member]
  )

  const {
    handleSubmit,
    control,
    setValue,
    watch,
    formState: { errors },
  } = useForm<BoardMemberValues>({
    resolver: zodResolver(schema),
    defaultValues,
  })

  const { catalogs } = useCatalogs(
    useMemo(() => ({ [CatalogType.BOARD_ROLE]: { _sort: 'order' } }), [])
  )

  const mutation = useMutation({
    mutationFn: (values: BoardMemberValues) => {
      const payload = {
        board_period_id: period.id,
        user_id: values.user_id,
        board_role_id: values.board_role_id,
      }
      return isEdit && member
        ? BoardMemberResource.update(member.id, payload)
        : BoardMemberResource.create(payload)
    },
    onSuccess: () => {
      toast.success(isEdit ? 'Miembro actualizado' : 'Miembro agregado — se asignó su rol de mesa')
      onSaved()
      onClose()
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo guardar el miembro'
      )
    },
  })

  const doSubmit = handleSubmit((values) => mutation.mutate(values))

  return (
    <>
      <DialogHeader className='shrink-0 px-6 pt-6'>
        <DialogTitle>{isEdit ? 'Editar miembro' : 'Agregar miembro'}</DialogTitle>
      </DialogHeader>

      <form className='px-6 py-2' onSubmit={doSubmit}>
        <FieldGroup>
          <RequiredFieldsLegend />
          <div className='grid grid-cols-12 gap-4'>
            <Field className='col-span-12'>
              <FieldLabel required>Persona</FieldLabel>
              <UserCombobox
                value={watch('user_id')}
                initialLabel={watch('user_name') || undefined}
                disabled={mutation.isPending}
                invalid={!!errors.user_id}
                placeholder='Buscar persona…'
                onSelect={(user) => {
                  setValue('user_id', user.id, { shouldValidate: true })
                  setValue('user_name', user.full_name ?? user.name)
                }}
                onClear={() => {
                  setValue('user_id', '')
                  setValue('user_name', '')
                }}
              />
              <FieldError>{errors.user_id?.message}</FieldError>
            </Field>

            <Field className='col-span-12'>
              <FieldLabel required>Cargo</FieldLabel>
              <Controller
                name='board_role_id'
                control={control}
                render={({ field }) => (
                  <SearchSelect
                    value={field.value}
                    onChange={(v) => field.onChange((v as string | null) ?? '')}
                    options={catalogs[CatalogType.BOARD_ROLE] ?? []}
                    isDisabled={mutation.isPending}
                    invalid={!!errors.board_role_id}
                    placeholder='Cargo…'
                  />
                )}
              />
              <FieldError>{errors.board_role_id?.message}</FieldError>
            </Field>
          </div>
        </FieldGroup>
      </form>

      <DialogFooter className='shrink-0 px-6 pb-6'>
        <Button
          type='button'
          variant='outline'
          onClick={onClose}
          disabled={mutation.isPending}
          className='min-w-[80px]'
        >
          Cancelar
        </Button>
        <Button
          type='button'
          onClick={doSubmit}
          disabled={mutation.isPending}
          className='min-w-[120px]'
        >
          {mutation.isPending ? 'Guardando…' : isEdit ? 'Guardar' : 'Agregar'}
        </Button>
      </DialogFooter>
    </>
  )
}
