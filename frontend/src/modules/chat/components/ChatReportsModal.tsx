import { Button } from '@/components/ui/button'
import {
  Dialog, DialogContent, DialogHeader, DialogTitle,
} from '@/components/ui/dialog'
import { useChatReports } from '@/modules/chat/hooks/useChatReports'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { EyeOff, X } from 'lucide-react'

interface ChatReportsModalProps {
  isOpen: boolean
  onClose: () => void
}

/** Cola de moderación: reportes pendientes de mensajes, con descartar u ocultar. */
export function ChatReportsModal({ isOpen, onClose }: ChatReportsModalProps) {
  const { reports, isLoading, dismissMutation, hideMutation } = useChatReports(isOpen)
  const pending = dismissMutation.isPending || hideMutation.isPending

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-lg" aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle>Reportes de moderación ({reports.length})</DialogTitle>
        </DialogHeader>

        {isLoading ? (
          <p className="py-8 text-center text-[13px] text-[var(--ink-3)]">Cargando reportes…</p>
        ) : reports.length === 0 ? (
          <p className="py-8 text-center text-[13px] text-[var(--ink-3)]">No hay reportes pendientes.</p>
        ) : (
          <ul className="max-h-[60vh] space-y-3 overflow-y-auto">
            {reports.map((report) => (
              <li key={report.id} className="rounded-[12px] border border-border p-3">
                <div className="rounded-[10px] bg-[var(--surface-2)] px-3 py-2 text-[13px]">
                  <p className="text-[11.5px] font-semibold text-[var(--ink-3)]">{report.message?.user?.name ?? 'Usuario'}</p>
                  <p className="whitespace-pre-wrap text-foreground">{report.message?.body ?? '(mensaje no disponible)'}</p>
                </div>
                <p className="mt-2 text-[12.5px] text-[var(--ink-2)]">
                  <span className="text-[var(--ink-3)]">Motivo:</span> {report.reason ?? '—'}
                </p>
                <p className="text-[11.5px] text-[var(--ink-4)]">
                  Reportó {report.reporter_user?.name ?? 'usuario'}{report.created_at ? ` · ${displayFormatDate(report.created_at)}` : ''}
                </p>
                <div className="mt-2 flex justify-end gap-2">
                  <Button
                    size="sm" variant="outline" className="h-7 rounded-full px-2.5 text-[12px]"
                    onClick={() => dismissMutation.mutate(report)}
                    disabled={pending}
                  >
                    <X className="size-3.5" />
                    Descartar
                  </Button>
                  <Button
                    size="sm" variant="outline" className="h-7 rounded-full px-2.5 text-[12px] text-destructive hover:text-destructive"
                    onClick={() => hideMutation.mutate(report)}
                    disabled={pending}
                  >
                    <EyeOff className="size-3.5" />
                    Ocultar mensaje
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </DialogContent>
    </Dialog>
  )
}
