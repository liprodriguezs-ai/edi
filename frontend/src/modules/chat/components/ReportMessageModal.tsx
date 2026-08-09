import { Button } from '@/components/ui/button'
import {
  Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle,
} from '@/components/ui/dialog'
import { Textarea } from '@/components/ui/textarea'
import type { IChatMessage } from '@/modules/chat/schema'
import { ChatMessageResource } from '@/modules/chat/services/ChatResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'

interface ReportMessageModalProps {
  target: IChatMessage | null
  onClose: () => void
}

/** Reportar un mensaje a la moderación del condominio (§13.6) con un motivo obligatorio. */
export function ReportMessageModal({ target, onClose }: ReportMessageModalProps) {
  const [reason, setReason] = useState('')

  // El motivo se limpia en cada cierre, así el próximo reporte abre en blanco.
  const close = () => {
    setReason('')
    onClose()
  }

  const mutation = useMutation({
    mutationFn: (message: IChatMessage) => ChatMessageResource.report(message.id, reason.trim()),
    onSuccess: () => {
      toast.success('Mensaje reportado — la moderación lo revisará')
      close()
    },
    onError: (error) => toast.error(error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo reportar'),
  })

  return (
    <Dialog open={Boolean(target)} onOpenChange={(open) => !open && close()}>
      <DialogContent className="max-w-md p-0" aria-describedby={undefined}>
        <DialogHeader className="px-6 pt-6">
          <DialogTitle>Reportar mensaje</DialogTitle>
        </DialogHeader>
        <div className="px-6 py-2">
          <p className="mb-3 text-[13px] text-[var(--ink-2)]">
            El mensaje se enviará a la moderación del condominio para su revisión.
          </p>
          <Textarea
            rows={3}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="Motivo del reporte (obligatorio)…"
            className="resize-none"
          />
        </div>
        <DialogFooter className="px-6 pb-6">
          <Button variant="outline" onClick={close} disabled={mutation.isPending}>
            Cancelar
          </Button>
          <Button
            variant="destructive"
            disabled={mutation.isPending || reason.trim().length === 0}
            onClick={() => target && mutation.mutate(target)}
          >
            {mutation.isPending ? 'Reportando…' : 'Reportar'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
