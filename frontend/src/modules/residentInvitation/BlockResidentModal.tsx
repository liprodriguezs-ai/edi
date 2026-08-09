import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field'
import { Textarea } from '@/components/ui/textarea'
import { ResidentInvitationResource } from '@/modules/residentInvitation/services/ResidentInvitationResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'

const blockSchema = z.object({
  reason: z
    .string()
    .min(10, 'El motivo debe tener al menos 10 caracteres')
    .max(1000, 'Máximo 1000 caracteres'),
})

type BlockFormValues = z.infer<typeof blockSchema>

interface BlockResidentModalProps {
  userId: string | null
  userName?: string | null
  isOpen: boolean
  onClose: () => void
}

/**
 * Bloquear residente (admin): deshabilita la cuenta (no puede iniciar sesión en
 * ningún clúster) Y lo agrega a la lista negra de acceso físico. Acción explícita
 * con motivo obligatorio que queda en la bitácora.
 */
export function BlockResidentModal({ userId, userName, isOpen, onClose }: BlockResidentModalProps) {
  const queryClient = useQueryClient()
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<BlockFormValues>({
    resolver: zodResolver(blockSchema),
    defaultValues: { reason: '' },
  })

  useEffect(() => {
    if (isOpen) reset({ reason: '' })
  }, [isOpen, reset])

  const mutation = useMutation({
    mutationFn: (values: BlockFormValues) => {
      if (!userId) throw new Error('Usuario no especificado')
      return ResidentInvitationResource.blockResident(userId, values.reason)
    },
    onSuccess: () => {
      toast.success({
        title: `${userName ?? 'El residente'} fue bloqueado`,
        description: 'Su cuenta quedó deshabilitada y se agregó a la lista negra de accesos.',
      })
      void queryClient.invalidateQueries({ queryKey: ['unit'] })
      onClose()
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError
          ? formatApiErrorMessage(error)
          : 'No se pudo bloquear al residente'
      )
    },
  })

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className='max-w-lg'>
        <DialogHeader>
          <DialogTitle>Bloquear residente</DialogTitle>
          <DialogDescription>
            Vas a bloquear a {userName ?? 'esta persona'}. Esto hace dos cosas a la vez:
          </DialogDescription>
        </DialogHeader>

        <ul className='list-disc space-y-1 pl-5 text-[13px] text-muted-foreground'>
          <li>Deshabilita su cuenta: no podrá iniciar sesión.</li>
          <li>Lo agrega a la lista negra de accesos: no podrá ingresar al condominio.</li>
        </ul>

        <form id='block-resident-form' onSubmit={handleSubmit((values) => mutation.mutate(values))}>
          <RequiredFieldsLegend />
          <Field>
            <FieldLabel htmlFor='block-reason' required>
              Motivo del bloqueo
            </FieldLabel>
            <Textarea
              id='block-reason'
              rows={3}
              className='resize-none'
              placeholder='Ej. orden de restricción / conflicto reportado por la mesa…'
              aria-invalid={!!errors.reason}
              {...register('reason')}
              disabled={mutation.isPending}
            />
            <FieldDescription>
              Quedará registrado en la bitácora. Mínimo 10 caracteres.
            </FieldDescription>
            <FieldError>{errors.reason?.message}</FieldError>
          </Field>
        </form>

        <DialogFooter>
          <Button type='button' variant='outline' onClick={onClose} disabled={mutation.isPending}>
            Cancelar
          </Button>
          <Button
            type='submit'
            form='block-resident-form'
            variant='destructive'
            disabled={mutation.isPending}
          >
            {mutation.isPending ? 'Bloqueando…' : 'Bloquear residente'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
