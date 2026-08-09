import { Button } from '@/components/ui/button'
import { UserForm } from '@/modules/user/UserForm'
import type { IUser } from '@/modules/user/schema'
import { useUserStore } from '@/modules/user/store/userStore'
import { Modal } from '@/shared/components/Modal'
import { CrudMode } from '@/shared/enums/CrudMode'
import type { IEntityFormModalProps } from '@/shared/interfaces/list.types'

const FORM_ID = 'user-form';

export const UserFormModal = ({
  isOpen,
  mode,
  entity,
  onClose,
  onSuccess,
}: IEntityFormModalProps<IUser>) => {
  const isSaving = useUserStore((s) => s.isSaving);

  const handleSuccess = (result: IUser) => {
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
      title={mode === CrudMode.EDIT ? 'Editar usuario' : 'Nuevo usuario'}
      size="lg"
      footer={footerActions}
    >
      <UserForm
        formId={FORM_ID}
        mode={mode}
        entity={entity || undefined}
        onSuccess={handleSuccess}
      />
    </Modal>
  );
};
