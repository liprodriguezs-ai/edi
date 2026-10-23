import { Button } from '@/components/ui/button'
import {
  Field,
  FieldDescription,
  FieldError,
  FieldGroup,
  FieldLabel,
  FieldLegend,
  FieldSet,
} from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { roleFormSchema, type IRole, type RoleFormValues } from '@/modules/role/schema'
import { RoleResource } from '@/modules/role/services/RoleResource'
import { useRoleStore } from '@/modules/role/store/roleStore'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useBaseCrud } from '@/shared/hooks/useBaseCrud'
import type { IFormProps } from '@/shared/interfaces/Entity'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useMemo } from 'react'
import { useForm } from 'react-hook-form'
import { FormFooter } from '@/shared/components/molecules/FormFooter'

export const RoleForm = ({ mode, entity, onSuccess, onCancel, formId }: IFormProps<IRole>) => {
  const defaultValues = useMemo<RoleFormValues>(() => {
    const baseDefaults: RoleFormValues = {
      name: '',
      description: '',
    }

    if (!entity) return baseDefaults

    return { ...baseDefaults, ...entity }
  }, [entity])

  const crud = useBaseCrud<IRole, RoleFormValues>({
    store: useRoleStore,
    resource: {
      save: RoleResource.create,
      update: RoleResource.update,
      delete: RoleResource.remove,
    },
    mode,
    entityId: entity?.id,
    initialData: entity,
  })

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<RoleFormValues>({
    resolver: zodResolver(roleFormSchema),
    defaultValues,
  })

  useEffect(() => {
    reset(defaultValues)
  }, [reset, defaultValues])

  const onFormSubmit = handleSubmit(async (values) => {
    const result = await crud.submit(values)
    if (result) {
      onSuccess?.(result)
    }
  })

  const isSaving = crud.store.isSaving

  return (
    <form id={formId} onSubmit={onFormSubmit}>
      <FieldGroup>
        <RequiredFieldsLegend />
        <FieldSet>
          <FieldLegend>Información del rol</FieldLegend>
          <FieldDescription>Datos principales del rol</FieldDescription>
          <FieldGroup>
            <Field>
              <FieldLabel htmlFor="role-description" required>
                Nombre
              </FieldLabel>
              <Input
                id="role-description"
                placeholder="Descripción del rol"
                {...register('description')}
                disabled={isSaving}
              />
              <FieldError>{errors.description?.message}</FieldError>
            </Field>

            <Field>
              <FieldLabel htmlFor="role-name" required>
                Slug
              </FieldLabel>
              <Input
                id="role-name"
                placeholder="slug-del-rol"
                {...register('name')}
                disabled={isSaving || mode === CrudMode.EDIT}
              />
              <FieldDescription>Identificador único del rol (no editable después de creado)</FieldDescription>
              <FieldError>{errors.name?.message}</FieldError>
            </Field>

            {/* <Field>
              <FieldLabel htmlFor="role-description">Descripción</FieldLabel>
              <Textarea
                id="role-description"
                placeholder="Descripción del rol..."
                className="resize-none"
                rows={4}
                {...register('description')}
                disabled={isSaving}
              />
              <FieldDescription>Detalle brevemente el propósito del rol</FieldDescription>
            </Field> */}
          </FieldGroup>
        </FieldSet>

        {!formId && (
          <FormFooter>
            {onCancel && (
              <Button
                type="button"
                variant="outline"
                onClick={onCancel}
                disabled={isSaving}
                className="min-w-[80px]"
              >
                Cancelar
              </Button>
            )}
            <Button type="submit" disabled={isSaving} className="min-w-[100px]">
              {isSaving
                ? mode === CrudMode.EDIT
                  ? 'Actualizando...'
                  : 'Creando...'
                : mode === CrudMode.EDIT
                  ? 'Actualizar'
                  : 'Crear'}
            </Button>
          </FormFooter>
        )}
      </FieldGroup>
    </form>
  )
}
