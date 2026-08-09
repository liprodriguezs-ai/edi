import { Button } from '@/components/ui/button'
import type { IUser } from '@/modules/user/schema'
import { UserResource } from '@/modules/user/services/UserResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { toast } from '@/shared/hooks/useToast'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { useRoles } from '@/shared/hooks/useRoles'
import { ERoleUserSlug } from '@/shared/interfaces/Entity'
import { useMutation } from '@tanstack/react-query'
import { LogOut } from 'lucide-react'
import { useState } from 'react'

const ALLOWED_ROLES = [
  ERoleUserSlug.SUPER_ADMIN,
  ERoleUserSlug.ACCOUNT_OWNER,
  ERoleUserSlug.ADMINISTRATOR,
]

/**
 * Cierra todas las sesiones activas de un usuario (seguridad / offboarding).
 * Solo visible para roles administrativos con `update.user`.
 */
export function RevokeSessionsAction({ user }: { user: IUser }) {
  const canUpdateUser = usePermissions('update.user')
  const { hasAnyRole } = useRoles()
  const [confirmOpen, setConfirmOpen] = useState(false)

  const mutation = useMutation({
    mutationFn: () => UserResource.revokeSessions(user.id),
    onSuccess: () => {
      toast.success('Sesiones cerradas')
      setConfirmOpen(false)
    },
    onError: (error) => toast.error(error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudieron cerrar las sesiones'),
  })

  if (!canUpdateUser || !hasAnyRole(ALLOWED_ROLES)) return null

  return (
    <>
      <Button
        variant="ghost"
        size="icon"
        className="size-8 text-[var(--ink-4)] hover:text-[var(--ink-1)]"
        onClick={() => setConfirmOpen(true)}
        aria-label="Cerrar sesiones del usuario"
        title="Cerrar todas las sesiones"
      >
        <LogOut className="size-4" />
      </Button>

      <ConfirmDialog
        isOpen={confirmOpen}
        isLoading={mutation.isPending}
        title="Cerrar sesiones"
        description={`Se cerrarán todas las sesiones activas de ${user.name ?? 'este usuario'}. Tendrá que volver a iniciar sesión en todos sus dispositivos.`}
        confirmLabel="Cerrar sesiones"
        onConfirm={() => mutation.mutate()}
        onCancel={() => setConfirmOpen(false)}
      />
    </>
  )
}
