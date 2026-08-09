import { Button } from '@/components/ui/button'
import { CollaboratorForm } from '@/modules/collaborator/CollaboratorForm'
import type { ICollaborator } from '@/modules/collaborator/schema'
import { useCollaboratorStore } from '@/modules/collaborator/store/collaboratorStore'
import { Modal } from '@/shared/components/Modal'
import { CrudMode } from '@/shared/enums/CrudMode'
import type { IEntityFormModalProps } from '@/shared/interfaces/list.types'

const FORM_ID = 'collaborator-form'

export const CollaboratorFormModal = ({
  isOpen,
  mode,
  entity,
  onClose,
  onSuccess,
}: IEntityFormModalProps<ICollaborator>) => {
  const isSaving = useCollaboratorStore((s) => s.isSaving)

  const handleSuccess = (result: ICollaborator) => {
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
      title={mode === CrudMode.EDIT ? 'Editar colaborador' : 'Nuevo colaborador'}
      size="lg"
      footer={footerActions}
    >
      <CollaboratorForm
        formId={FORM_ID}
        mode={mode}
        entity={entity || undefined}
        onSuccess={handleSuccess}
      />
    </Modal>
  )
}
