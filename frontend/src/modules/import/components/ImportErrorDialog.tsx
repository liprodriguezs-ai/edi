import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogMedia,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { IconAlertTriangle } from '@tabler/icons-react'

interface ImportErrorDialogProps {
  open: boolean
  /** Short headline. Defaults to a generic import failure title. */
  title?: string
  /** Main, user-facing error message (shown as the dialog description). */
  message: string
  onClose: () => void
}

/**
 * Blocking error dialog for the import wizard, used ONLY for hard failures
 * (unreadable file / missing PAGOS sheet, template download failure, network).
 * Per-row validation errors and warnings are NOT shown here — they are reviewed
 * in Step 2 (ValidateStep). Replaces transient sileo toasts so the user must
 * acknowledge the failure.
 */
export function ImportErrorDialog({
  open,
  title = 'No se pudo importar el archivo',
  message,
  onClose,
}: ImportErrorDialogProps) {
  return (
    <AlertDialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogMedia className="bg-destructive/10 text-destructive">
            <IconAlertTriangle className="size-8" aria-hidden />
          </AlertDialogMedia>
          <AlertDialogTitle>{title}</AlertDialogTitle>
          <AlertDialogDescription>{message}</AlertDialogDescription>
        </AlertDialogHeader>

        <AlertDialogFooter>
          <AlertDialogAction onClick={onClose}>Entendido</AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
