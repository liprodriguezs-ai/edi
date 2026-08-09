import { Button } from '@/components/ui/button'
import { RoleForm } from '@/modules/role/RoleForm'
import type { IRole } from '@/modules/role/schema'
import { useRoleStore } from '@/modules/role/store/roleStore'
import { Modal } from '@/shared/components/Modal'
import { CrudMode } from '@/shared/enums/CrudMode'
import type { IEntityFormModalProps } from '@/shared/interfaces/list.types'

const FORM_ID = 'role-form'

export const RoleFormModal = ({
  isOpen,
  mode,
  entity,
  onClose,
  onSuccess,
}: IEntityFormModalProps<IRole>) => {
  const isSaving = useRoleStore((s) => s.isSaving)

  const handleSuccess = (result: IRole) => {
    onSuccess?.(result)
    onClose()
  }

  if (!isOpen) {
    return null
  }

  const footerActions = (
    <>
      <Button
        type="button"
        variant="outline"
        onClick={onClose}
        disabled={isSaving}
        className="min-w-[80px]"
      >
        Cancelar
      </Button>
      <Button type="submit" form={FORM_ID} disabled={isSaving} className="min-w-[100px]">
        {isSaving
          ? mode === CrudMode.EDIT
            ? 'Actualizando...'
            : 'Creando...'
          : mode === CrudMode.EDIT
            ? 'Actualizar'
            : 'Crear'}
      </Button>
    </>
  )

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={mode === CrudMode.EDIT ? 'Editar rol' : 'Nuevo rol'}
      size="lg"
      footer={footerActions}
    >
      <RoleForm
        formId={FORM_ID}
        mode={mode}
        entity={entity || undefined}
        onSuccess={handleSuccess}
      />
    </Modal>
  )
}
