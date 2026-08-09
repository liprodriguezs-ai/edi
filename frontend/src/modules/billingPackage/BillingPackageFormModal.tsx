import { Button } from '@/components/ui/button'
import { BillingPackageForm } from '@/modules/billingPackage/BillingPackageForm'
import type { IBillingPackage } from '@/modules/billingPackage/schema'
import { useBillingPackageStore } from '@/modules/billingPackage/store/billingPackageStore'
import { Modal } from '@/shared/components/Modal'
import { CrudMode } from '@/shared/enums/CrudMode'
import type { IEntityFormModalProps } from '@/shared/interfaces/list.types'

const FORM_ID = 'billing-package-form'

export const BillingPackageFormModal = ({
  isOpen,
  mode,
  entity,
  onClose,
  onSuccess,
}: IEntityFormModalProps<IBillingPackage>) => {
  const isSaving = useBillingPackageStore((s) => s.isSaving)

  const handleSuccess = (result: IBillingPackage) => {
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
      <Button
        type="submit"
        form={FORM_ID}
        disabled={isSaving}
        className="min-w-[100px]"
      >
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
      title={mode === CrudMode.EDIT ? 'Editar plan' : 'Nuevo plan de facturación'}
      size="lg"
      footer={footerActions}
    >
      <BillingPackageForm
        formId={FORM_ID}
        mode={mode}
        entity={entity ?? undefined}
        onSuccess={handleSuccess}
      />
    </Modal>
  )
}
