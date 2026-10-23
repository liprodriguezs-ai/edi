import { Button } from '@/components/ui/button'
import {
  Field,
  FieldError,
  FieldGroup,
  FieldLabel,
  FieldLegend,
  FieldSet,
} from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { userFormSchema, type IUser, type UserFormValues } from '@/modules/user/schema'
import { UserResource } from '@/modules/user/services/UserResource'
import { useUserStore } from '@/modules/user/store/userStore'
import { PasswordStrength } from '@/shared/components/PasswordStrength'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { PASSWORD_REQUIREMENTS_TEXT } from '@/shared/constants/passwordRequirements'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useBaseCrud } from '@/shared/hooks/useBaseCrud'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import { type IFormProps } from '@/shared/interfaces/Entity'
import { zodResolver } from '@hookform/resolvers/zod'
import { IconEye, IconEyeOff, IconInfoCircle } from '@tabler/icons-react'
import { useEffect, useMemo, useState } from 'react'
import { Controller, useForm, type FieldErrors, type UseFormRegister } from 'react-hook-form'
import { FormFooter } from '@/shared/components/molecules/FormFooter'

interface PasswordFieldsProps {
  mode: CrudMode
  register: UseFormRegister<UserFormValues>
  errors: FieldErrors<UserFormValues>
  disabled: boolean
  passwordValue?: string
}

interface UserPasswordInputRowProps {
  id: string
  label: string
  showTooltip: boolean
  errorMessage?: string
  errorId: string
  show: boolean
  onToggleShow: () => void
  placeholder: string
  disabled: boolean
  name: 'password' | 'password_confirmation'
  register: UseFormRegister<UserFormValues>
}

function UserPasswordInputRow({
  id,
  label,
  showTooltip,
  errorMessage,
  errorId,
  show,
  onToggleShow,
  placeholder,
  disabled,
  name,
  register,
}: UserPasswordInputRowProps) {
  return (
    <Field>
      {showTooltip ? (
        <div className="flex items-center gap-1.5">
          <FieldLabel htmlFor={id}>{label}</FieldLabel>
          <Tooltip>
            <TooltipTrigger asChild>
              <button
                type="button"
                className="inline-flex text-muted-foreground hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded"
                aria-label="Requisitos de contraseña"
              >
                <IconInfoCircle className="h-4 w-4" />
              </button>
            </TooltipTrigger>
            <TooltipContent side="top" className="max-w-xs">
              {PASSWORD_REQUIREMENTS_TEXT}
            </TooltipContent>
          </Tooltip>
        </div>
      ) : (
        <FieldLabel htmlFor={id}>{label}</FieldLabel>
      )}
      <div className="relative">
        <Input
          id={id}
          type={show ? 'text' : 'password'}
          autoComplete="new-password"
          placeholder={placeholder}
          className="pr-10"
          aria-invalid={!!errorMessage}
          aria-describedby={errorMessage ? errorId : undefined}
          disabled={disabled}
          {...register(name)}
        />
        <button
          type="button"
          className="absolute inset-y-0 right-0 flex items-center pr-3 text-muted-foreground hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded"
          onClick={onToggleShow}
          aria-label={show ? 'Ocultar contraseña' : 'Mostrar contraseña'}
        >
          {show ? <IconEyeOff className="h-4 w-4" /> : <IconEye className="h-4 w-4" />}
        </button>
      </div>
      <FieldError id={errorId} role="alert">
        {errorMessage}
      </FieldError>
    </Field>
  )
}

const PasswordFields = ({
  mode,
  register,
  errors,
  disabled,
  passwordValue = '',
}: PasswordFieldsProps) => {
  const [showPassword, setShowPassword] = useState(false)
  const [showConfirmPassword, setShowConfirmPassword] = useState(false)

  const passwordLabel =
    mode === CrudMode.EDIT
      ? 'Nueva contraseña (dejar vacío para no cambiar)'
      : 'Contraseña'

  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
      <div className="space-y-2">
        <UserPasswordInputRow
          id="user-password"
          label={passwordLabel}
          showTooltip
          errorMessage={errors.password?.message}
          errorId="user-password-error"
          show={showPassword}
          onToggleShow={() => setShowPassword((prev) => !prev)}
          placeholder={
            mode === CrudMode.EDIT ? '••••••••' : 'Mín. 8 caracteres, mayúscula, minúscula y número'
          }
          disabled={disabled}
          name="password"
          register={register}
        />
        {passwordValue ? (
          <PasswordStrength
            value={passwordValue}
            id="user-password-strength"
            showLabel
          />
        ) : null}
      </div>
      <UserPasswordInputRow
        id="user-password-confirmation"
        label="Confirmar contraseña"
        showTooltip={false}
        errorMessage={errors.password_confirmation?.message}
        errorId="user-password-confirmation-error"
        show={showConfirmPassword}
        onToggleShow={() => setShowConfirmPassword((prev) => !prev)}
        placeholder="Repetir contraseña"
        disabled={disabled}
        name="password_confirmation"
        register={register}
      />
    </div>
  )
}

interface UserFormExtendedProps extends IFormProps<IUser> {
  hideRole?: boolean
  tenantId?: string
}

export const UserForm = ({ mode, entity, onSuccess, onCancel, formId, hideRole, tenantId }: UserFormExtendedProps) => {
  const defaultValues = useMemo<UserFormValues>(() => {
    const baseDefaults: UserFormValues = {
      name: '',
      last_name: '',
      second_last_name: '',
      phone: '',
      email: '',
      password: '',
      password_confirmation: '',
      role_id: '',
    }

    if (!entity) return baseDefaults

    const role = (entity as IUser).role ?? (entity as IUser).roles?.[0]
    const roleId =
      entity.role_id ??
      role?.id ??
      (role as { uuid?: string } | undefined)?.uuid ??
      ''

    return {
      ...baseDefaults,
      ...entity,
      password: '',
      password_confirmation: '',
      role_id: roleId,
    }
  }, [entity])

  const crud = useBaseCrud<IUser, UserFormValues>({
    store: useUserStore,
    resource: {
      save: UserResource.create,
      update: UserResource.update,
      delete: UserResource.remove,
    },
    mode,
    entityId: entity?.id,
    initialData: entity,
  })

  const {
    register,
    handleSubmit,
    reset,
    control,
    watch,
    formState: { errors },
  } = useForm<UserFormValues>({
    resolver: zodResolver(userFormSchema),
    defaultValues,
  })

  useEffect(() => {
    reset(defaultValues)
  }, [reset, defaultValues])

  const onFormSubmit = handleSubmit(async (values) => {
    const payload: Record<string, unknown> = { ...values }
    delete payload.password_confirmation
    if (!payload.password) {
      delete payload.password
    }
    if (tenantId) {
      payload.tenant_id = tenantId
    }
    const result = await crud.submit(payload as UserFormValues)
    if (result) {
      onSuccess?.(result)
    }
  })

  const { catalogs } = useCatalogs({
    [CatalogType.ROLE]: { _sort: 'name' },
  })

  const isSaving = crud.store.isSaving

  return (
    <form id={formId} onSubmit={onFormSubmit}>
      <FieldGroup>
        <RequiredFieldsLegend />
        <FieldSet>
          <FieldLegend>Información personal</FieldLegend>
          <FieldGroup>
            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
              <Field>
                <FieldLabel htmlFor="user-name" required>
                  Nombre
                </FieldLabel>
                <Input
                  id="user-name"
                  placeholder="Nombre"
                  {...register('name')}
                  disabled={isSaving}
                />
                <FieldError>{errors.name?.message}</FieldError>
              </Field>

              <Field>
                <FieldLabel required htmlFor="user-last-name">Primer apellido</FieldLabel>
                <Input
                  id="user-last-name"
                  placeholder="Primer apellido"
                  {...register('last_name')}
                  disabled={isSaving}
                />
              </Field>

              <Field>
                <FieldLabel htmlFor="user-second-last-name">Segundo Apellido</FieldLabel>
                <Input
                  id="user-second-last-name"
                  placeholder="Segundo Apellido"
                  {...register('second_last_name')}
                  disabled={isSaving}
                />
              </Field>
            </div>

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              <Field>
                <FieldLabel htmlFor="user-email" required>
                  Correo electrónico
                </FieldLabel>
                <Input
                  id="user-email"
                  type="email"
                  placeholder="correo@ejemplo.com"
                  {...register('email')}
                  disabled={isSaving}
                />
                <FieldError>{errors.email?.message}</FieldError>
              </Field>

              <Field>
                <FieldLabel htmlFor="user-phone">Teléfono</FieldLabel>
                <Input
                  id="user-phone"
                  type="tel"
                  placeholder="+52 55 1234 5678"
                  {...register('phone')}
                  disabled={isSaving}
                />
              </Field>
            </div>

            {!hideRole && (
              <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <Field>
                  <FieldLabel htmlFor="user-role" required>
                    Rol
                  </FieldLabel>
                  <Controller
                    name="role_id"
                    control={control}
                    render={({ field }) => (
                      <SearchSelect
                        id="user-role"
                        value={field.value}
                        onChange={(v) => field.onChange((v as string | null) ?? '')}
                        options={catalogs[CatalogType.ROLE] ?? []}
                        isDisabled={isSaving}
                        invalid={!!errors.role_id}
                        placeholder="Seleccionar rol..."
                        getOptionValue={(o) => o.uuid as string}
                        getOptionLabel={(o) => o.description as string}
                      />
                    )}
                  />
                  <FieldError>{errors.role_id?.message}</FieldError>
                </Field>
              </div>
            )}

            <PasswordFields
              mode={mode}
              register={register}
              errors={errors}
              disabled={isSaving}
              passwordValue={watch('password')}
            />
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
