import { AlertDialogMedia } from '@/components/ui/alert-dialog'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { usePermissionsModal } from '@/shared/hooks/usePermissionsModal'
import { ShieldAlert } from 'lucide-react'

/**
 * Modal global que se muestra cuando la API devuelve 403 (sin permiso).
 * El interceptor de apiClient abre este modal vía usePermissionsModal.getState().openModal().
 */
export function PermissionsModal() {
  const { isOpen, message, action, closeModal } = usePermissionsModal()

  return (
    <ConfirmDialog
      isOpen={isOpen}
      hideCancel
      title="Sin permiso"
      confirmLabel="Entendido"
      onConfirm={closeModal}
      onCancel={closeModal}
      media={
        <AlertDialogMedia className="bg-[var(--ybg)] text-[var(--y)]">
          <ShieldAlert className="size-8" aria-hidden />
        </AlertDialogMedia>
      }
      description={
        <span className="block space-y-1">
          <span className="block">{message}</span>
          {action && action !== 'acción solicitada' && (
            <span className="text-muted-foreground block font-mono text-xs">{action}</span>
          )}
        </span>
      }
    />
  )
}
