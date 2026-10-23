import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Textarea } from '@/components/ui/textarea'
import { followUpCancelFormSchema, type FollowUpCancelFormValues } from '@/modules/followUp/schema'
import { FollowUpResource } from '@/modules/followUp/services/FollowUpResource'
import { followUpErrorMessage } from '@/modules/followUp/utils/followUpErrors'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'

interface FollowUpCancelModalProps {
  followUpId: string
  onClose: () => void
  onDone: () => void
}

/** REQ-FUP-006 — reason is mandatory. Terminal, no undo. */
export function FollowUpCancelModal({ followUpId, onClose, onDone }: FollowUpCancelModalProps) {
  const [isSaving, setIsSaving] = useState(false)

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<FollowUpCancelFormValues>({
    resolver: zodResolver(followUpCancelFormSchema),
    defaultValues: { reason: '' },
  })

  const doSubmit = handleSubmit(async (values) => {
    setIsSaving(true)
    try {
      await FollowUpResource.cancel(followUpId, values)
      toast.success('Seguimiento cancelado')
      onDone()
    } catch (err) {
      toast.error(followUpErrorMessage(err, 'No se pudo cancelar el seguimiento'))
    } finally {
      setIsSaving(false)
    }
  })

  return (
    <ConfirmDialog
      isOpen
      title="Cancelar seguimiento"
      description="El seguimiento quedará cancelado. Esta acción no se puede deshacer."
      confirmLabel="Cancelar seguimiento"
      cancelLabel="Volver"
      destructive
      loadingLabel="Cancelando…"
      isLoading={isSaving}
      onConfirm={() => void doSubmit()}
      onCancel={onClose}
    >
      <form
        onSubmit={(e) => {
          e.preventDefault()
          void doSubmit(e)
        }}
      >
        <RequiredFieldsLegend />
        <FieldGroup>
          <Field>
            <FieldLabel htmlFor="cancel-reason" required>
              Motivo
            </FieldLabel>
            <Textarea id="cancel-reason" rows={3} disabled={isSaving} {...register('reason')} />
            <FieldError>{errors.reason?.message}</FieldError>
          </Field>
        </FieldGroup>
      </form>
    </ConfirmDialog>
  )
}
