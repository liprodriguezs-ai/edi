import { Button } from '@/components/ui/button'
import {
  Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle,
} from '@/components/ui/dialog'
import { Field, FieldError, FieldLabel } from '@/components/ui/field'
import { Textarea } from '@/components/ui/textarea'
import type { IAccessLog } from '@/modules/access/schema'
import { AccessLogResource } from '@/modules/access/services/AccessResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'

const correctionFormSchema = z.object({
  note: z.string().min(1, 'La corrección no puede estar vacía').max(1000),
})

type CorrectionFormValues = z.infer<typeof correctionFormSchema>

interface CorrectionModalProps {
  log: IAccessLog | null
  onClose: () => void
  onSaved: () => void
}

/**
 * Agrega una corrección INMUTABLE a un registro de la bitácora (§14.5): la
 * bitácora nunca se edita ni borra, solo se le anexan notas de corrección.
 */
export function CorrectionModal({ log, onClose, onSaved }: CorrectionModalProps) {
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<CorrectionFormValues>({
    resolver: zodResolver(correctionFormSchema),
    defaultValues: { note: '' },
  })

  useEffect(() => {
    if (log) {
      reset({ note: '' })
    }
  }, [log, reset])

  const close = () => {
    reset({ note: '' })
    onClose()
  }

  const mutation = useMutation({
    mutationFn: ({ entry, note }: { entry: IAccessLog; note: string }) =>
      AccessLogResource.addCorrection(entry.id, note.trim()),
    onSuccess: () => {
      toast.success('Corrección registrada')
      onSaved()
      close()
    },
    onError: (error) =>
      toast.error(error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo registrar la corrección'),
  })

  return (
    <Dialog open={Boolean(log)} onOpenChange={(open) => !open && close()}>
      <DialogContent className="max-w-md p-0" aria-describedby={undefined}>
        <DialogHeader className="px-6 pt-6">
          <DialogTitle>Agregar corrección</DialogTitle>
        </DialogHeader>
        <form
          className="px-6 py-2"
          onSubmit={handleSubmit((values) => log && mutation.mutate({ entry: log, note: values.note }))}
        >
          <RequiredFieldsLegend />
          {log?.correction && (
            <div className="mb-3 rounded-[10px] border border-border bg-[var(--surface-2)] px-3 py-2 text-[12.5px] text-[var(--ink-2)]">
              <p className="text-[11px] font-semibold uppercase tracking-[0.06em] text-[var(--ink-3)]">Corrección previa</p>
              <p className="mt-0.5 whitespace-pre-wrap">{log.correction}</p>
            </div>
          )}
          <p className="mb-2 text-[13px] text-[var(--ink-2)]">
            La nota se anexa de forma permanente; el registro original no se modifica.
          </p>
          <Field>
            <FieldLabel htmlFor="correction-note" required>Nota de corrección</FieldLabel>
            <Textarea
              id="correction-note"
              rows={3}
              {...register('note')}
              placeholder="Describe la corrección…"
              className="resize-none"
              maxLength={1000}
              disabled={mutation.isPending}
            />
            <FieldError>{errors.note?.message}</FieldError>
          </Field>
        </form>
        <DialogFooter className="px-6 pb-6">
          <Button type="button" variant="outline" onClick={close} disabled={mutation.isPending}>Cancelar</Button>
          <Button
            type="button"
            disabled={mutation.isPending}
            onClick={handleSubmit((values) => log && mutation.mutate({ entry: log, note: values.note }))}
          >
            {mutation.isPending ? 'Guardando…' : 'Guardar corrección'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
