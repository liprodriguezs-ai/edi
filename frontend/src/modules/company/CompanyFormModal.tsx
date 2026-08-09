import { Button } from '@/components/ui/button'
import { CompanyForm } from '@/modules/company/CompanyForm'
import type { ICompany } from '@/modules/company/schema'
import { useCompanyStore } from '@/modules/company/store/companyStore'
import { Modal } from '@/shared/components/Modal'
import { CrudMode } from '@/shared/enums/CrudMode'
import type { IEntityFormModalProps } from '@/shared/interfaces/list.types'

const FORM_ID = 'company-form';

export const CompanyFormModal = ({
  isOpen,
  mode,
  entity,
  onClose,
  onSuccess,
}: IEntityFormModalProps<ICompany>) => {
  const isSaving = useCompanyStore((s) => s.isSaving);

  const handleSuccess = (result: ICompany) => {
    onSuccess?.(result);
    onClose();
  };

  if (!isOpen) {
    return null;
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
  );

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={mode === CrudMode.EDIT ? 'Editar empresa' : 'Nueva empresa'}
      size="xl"
      footer={footerActions}
    >
      <CompanyForm
        formId={FORM_ID}
        mode={mode}
        entity={entity || undefined}
        onSuccess={handleSuccess}
      />
    </Modal>
  );
};

