import { Button } from '@/components/ui/button'
import { TenantForm } from '@/modules/tenant/TenantForm'
import type { ITenant } from '@/modules/tenant/schema'
import { useTenantStore } from '@/modules/tenant/store/tenantStore'
import { Modal } from '@/shared/components/Modal'
import { CrudMode } from '@/shared/enums/CrudMode'
import type { IEntityFormModalProps } from '@/shared/interfaces/list.types'

const FORM_ID = 'tenant-form'

export const TenantFormModal = ({
  isOpen,
  mode,
  entity,
  onClose,
  onSuccess,
}: IEntityFormModalProps<ITenant>) => {
  const isSaving = useTenantStore((s) => s.isSaving)

  const handleSuccess = (result: ITenant) => {
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
      title={mode === CrudMode.EDIT ? 'Editar tenant' : 'Nuevo tenant'}
      size="md"
      footer={footerActions}
    >
      <TenantForm
        formId={FORM_ID}
        mode={mode}
        entity={entity || undefined}
        onSuccess={handleSuccess}
      />
    </Modal>
  )
}
