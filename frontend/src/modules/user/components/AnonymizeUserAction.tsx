import { Button } from '@/components/ui/button'
import type { IUser } from '@/modules/user/schema'
import { UserResource } from '@/modules/user/services/UserResource'
import { useUserStore } from '@/modules/user/store/userStore'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { toast } from '@/shared/hooks/useToast'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { useRoles } from '@/shared/hooks/useRoles'
import { ERoleUserSlug } from '@/shared/interfaces/Entity'
import { useMutation } from '@tanstack/react-query'
import { UserX } from 'lucide-react'
import { useState } from 'react'

const ALLOWED_ROLES = [
  ERoleUserSlug.SUPER_ADMIN,
  ERoleUserSlug.ACCOUNT_OWNER,
  ERoleUserSlug.ADMINISTRATOR,
]

/**
 * Acción de "derecho al olvido" (§18.3.4): anonimiza de forma irreversible un
 * usuario. Solo visible para roles administrativos con `update.user` (el backend
 * vuelve a validarlo y rechaza la propia cuenta o el último propietario).
 */
export function AnonymizeUserAction({ user }: { user: IUser }) {
  const canUpdateUser = usePermissions('update.user')
  const { hasAnyRole } = useRoles()
  const updateEntity = useUserStore((s) => s.updateEntity)
  const [confirmOpen, setConfirmOpen] = useState(false)

  const mutation = useMutation({
    mutationFn: () => UserResource.anonymize(user.id),
    onSuccess: (result) => {
      toast.success('Usuario anonimizado')
      updateEntity({ id: result.id, name: result.name })
      setConfirmOpen(false)
    },
    onError: (error) => toast.error(error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo anonimizar el usuario'),
  })

  if (!canUpdateUser || !hasAnyRole(ALLOWED_ROLES)) return null

  return (
    <>
      <Button
        variant="ghost"
        size="icon"
        className="size-8 text-[var(--ink-4)] hover:text-destructive"
        onClick={() => setConfirmOpen(true)}
        aria-label="Anonimizar usuario"
        title="Anonimizar (derecho al olvido)"
      >
        <UserX className="size-4" />
      </Button>

      <ConfirmDialog
        isOpen={confirmOpen}
        variant="delete"
        isLoading={mutation.isPending}
        title="Anonimizar usuario"
        description={`Se eliminarán de forma IRREVERSIBLE los datos personales de ${user.name ?? 'este usuario'} (nombre, correo, teléfono). Esta acción no se puede deshacer. ¿Continuar?`}
        confirmLabel="Anonimizar"
        onConfirm={() => mutation.mutate()}
        onCancel={() => setConfirmOpen(false)}
      />
    </>
  )
}
