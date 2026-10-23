import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Textarea } from '@/components/ui/textarea'
import { invalidateFormSchema, type InvalidateFormValues } from '@/modules/screening/schema'
import { ScreeningResource } from '@/modules/screening/services/ScreeningResource'
import { screeningErrorMessage } from '@/modules/screening/utils/screeningErrors'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'

interface InvalidateModalProps {
  screeningId: string
  onClose: () => void
  onDone: () => void
}

/** REQ-SUI-008 — reason required, gated by `delete.screening`. Terminal, no undo. */
export function InvalidateModal({ screeningId, onClose, onDone }: InvalidateModalProps) {
  const [isSaving, setIsSaving] = useState(false)

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<InvalidateFormValues>({
    resolver: zodResolver(invalidateFormSchema),
    defaultValues: { reason: '' },
  })

  const doSubmit = handleSubmit(async (values) => {
    setIsSaving(true)
    try {
      await ScreeningResource.invalidate(screeningId, values)
      toast.success('Prueba EDI anulada')
      onDone()
    } catch (err) {
      toast.error(screeningErrorMessage(err, 'No se pudo anular la Prueba EDI'))
    } finally {
      setIsSaving(false)
    }
  })

  return (
    <ConfirmDialog
      isOpen
      title="Anular prueba"
      description="Úsalo si la prueba se capturó por error (por ejemplo, a otro niño). Queda marcada como anulada y no se puede deshacer."
      confirmLabel="Anular prueba"
      destructive
      loadingLabel="Anulando…"
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
            <FieldLabel htmlFor="invalidate-reason" required>
              Motivo
            </FieldLabel>
            <Textarea id="invalidate-reason" rows={3} disabled={isSaving} {...register('reason')} />
            <FieldError>{errors.reason?.message}</FieldError>
          </Field>
        </FieldGroup>
      </form>
    </ConfirmDialog>
  )
}
