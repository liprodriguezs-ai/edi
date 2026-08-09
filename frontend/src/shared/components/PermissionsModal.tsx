import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogMedia,
  AlertDialogTitle
} from '@/components/ui/alert-dialog'
import { usePermissionsModal } from '@/shared/hooks/usePermissionsModal'
import { ShieldAlert } from 'lucide-react'

/**
 * Modal global que se muestra cuando la API devuelve 403 (sin permiso).
 * El interceptor de apiClient abre este modal vía usePermissionsModal.getState().openModal().
 */
export function PermissionsModal() {
  const { isOpen, message, action, closeModal } = usePermissionsModal()

  return (
    <AlertDialog open={isOpen} onOpenChange={(open) => !open && closeModal()}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogMedia className="bg-amber-500/10 text-amber-600 dark:text-amber-500">
            <ShieldAlert className="size-8" aria-hidden />
          </AlertDialogMedia>
          <AlertDialogTitle id="permissions-modal-title">
            Sin permiso
          </AlertDialogTitle>
          <AlertDialogDescription id="permissions-modal-description">
            <span className="block space-y-1">
              <span className="block">{message}</span>
              {action && action !== 'acción solicitada' && (
                <span className="text-muted-foreground block font-mono text-xs">
                  {action}
                </span>
              )}
            </span>
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogAction onClick={closeModal}>
            Entendido
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
