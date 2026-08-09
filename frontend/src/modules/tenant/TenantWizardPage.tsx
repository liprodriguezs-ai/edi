import { Button } from '@/components/ui/button'
import { TenantProvisionForm } from '@/modules/tenant/TenantProvisionForm'
import type { ITenant } from '@/modules/tenant/schema'
import { UserForm } from '@/modules/user/UserForm'
import { CrudMode } from '@/shared/enums/CrudMode'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import { ERoleUserSlug } from '@/shared/interfaces/Entity'
import { useTenantStore } from '@/modules/tenant/store/tenantStore'
import { useUserStore } from '@/modules/user/store/userStore'
import { IconArrowLeft, IconCheck, IconUser, IconBuilding } from '@tabler/icons-react'
import { useCallback, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

const TENANT_FORM_ID = 'wizard-tenant-form'
const USER_FORM_ID = 'wizard-user-form'

const STEPS = [
  {
    title: 'Datos del Tenant',
    description: 'Información del espacio de trabajo',
    icon: IconBuilding,
  },
  { title: 'Crear Usuario', description: 'Usuario administrador del tenant', icon: IconUser },
] as const

export const TenantWizardPage = () => {
  const navigate = useNavigate()
  const [currentStep, setCurrentStep] = useState(0)
  const [createdTenant, setCreatedTenant] = useState<ITenant | null>(null)
  // El paso 1 puede quedar bloqueado por su propia combinación de plan/modo de
  // provisión (plan a medida + pago manual). El form lo reporta.
  const [canSubmitTenant, setCanSubmitTenant] = useState(true)

  const isSavingTenant = useTenantStore((s) => s.isSaving)
  const isSavingUser = useUserStore((s) => s.isSaving)

  // Lazy: el catálogo ROLE sólo se usa en el paso 1 (resolver el rol account owner),
  // así que difiere el POST /selects hasta llegar a ese paso (costo de infra).
  const { catalogs } = useCatalogs(
    { [CatalogType.ROLE]: { _sort: 'name' } },
    { enabled: currentStep === 1 }
  )

  const accountOwnerRoleId = useMemo(() => {
    const roles = catalogs[CatalogType.ROLE] || []
    const role = roles.find((r) => r.name === ERoleUserSlug.ACCOUNT_OWNER)
    // El catálogo de roles (modelo central Spatie) expone su PK como `uuid`, no `id`.
    return (role as { uuid?: string } | undefined)?.uuid
  }, [catalogs])

  const handleTenantSuccess = useCallback((tenant: ITenant) => {
    setCreatedTenant(tenant)
    setCurrentStep(1)
  }, [])

  const handleUserSuccess = useCallback(() => {
    navigate('/tenant')
  }, [navigate])

  const handleCancel = useCallback(() => {
    navigate('/tenant')
  }, [navigate])

  return (
    <div className='space-y-8'>
      <div className='flex items-center gap-4'>
        <Button variant='ghost' size='icon' onClick={handleCancel}>
          <IconArrowLeft className='h-5 w-5' />
        </Button>
        <div>
          <h1 className='text-2xl font-bold tracking-tight'>Nuevo Tenant</h1>
          <p className='text-muted-foreground'>
            Configura el espacio de trabajo y su usuario administrador
          </p>
        </div>
      </div>

      {/* Stepper */}
      <nav aria-label='Progreso' className='mx-auto max-w-lg'>
        <ol className='flex items-center'>
          {STEPS.map((step, index) => {
            const isCompleted = index < currentStep
            const isCurrent = index === currentStep
            const StepIcon = step.icon

            return (
              <li
                key={step.title}
                className={`flex items-center ${index < STEPS.length - 1 ? 'flex-1' : ''}`}
              >
                <div className='flex flex-col items-center gap-2 text-center'>
                  <div
                    className={`flex h-10 w-10 items-center justify-center rounded-full border-2 transition-colors ${
                      isCompleted
                        ? 'border-primary bg-primary text-primary-foreground'
                        : isCurrent
                          ? 'border-primary bg-background text-primary'
                          : 'border-muted-foreground/30 bg-background text-muted-foreground/50'
                    }`}
                  >
                    {isCompleted ? (
                      <IconCheck className='h-5 w-5' />
                    ) : (
                      <StepIcon className='h-5 w-5' />
                    )}
                  </div>
                  <div className='min-w-[120px]'>
                    <p
                      className={`text-sm font-medium ${isCurrent || isCompleted ? 'text-foreground' : 'text-muted-foreground/50'}`}
                    >
                      {step.title}
                    </p>
                    <p className='text-xs text-muted-foreground hidden sm:block'>
                      {step.description}
                    </p>
                  </div>
                </div>

                {index < STEPS.length - 1 && (
                  <div className='mx-4 mb-8 flex-1'>
                    <div
                      className={`h-0.5 w-full transition-colors ${isCompleted ? 'bg-primary' : 'bg-muted-foreground/20'}`}
                    />
                  </div>
                )}
              </li>
            )
          })}
        </ol>
      </nav>

      {/* Step Content */}
      <div className='mx-auto max-w-2xl rounded-lg border bg-card p-6 shadow-sm'>
        {currentStep === 0 && (
          <TenantProvisionForm
            formId={TENANT_FORM_ID}
            onSuccess={handleTenantSuccess}
            onCanSubmitChange={setCanSubmitTenant}
          />
        )}

        {currentStep === 1 && accountOwnerRoleId && (
          <UserForm
            formId={USER_FORM_ID}
            mode={CrudMode.CREATE}
            entity={{ role_id: accountOwnerRoleId }}
            hideRole
            tenantId={createdTenant?.id}
            onSuccess={handleUserSuccess}
          />
        )}

        {currentStep === 1 && !accountOwnerRoleId && (
          <div className='flex items-center justify-center py-12'>
            <p className='text-muted-foreground'>Cargando configuración de roles...</p>
          </div>
        )}
      </div>

      {/* Footer Actions */}
      <div className='mx-auto flex max-w-2xl items-center justify-end gap-3'>
        {currentStep === 0 && (
          <>
            <Button
              type='button'
              variant='outline'
              onClick={handleCancel}
              disabled={isSavingTenant}
            >
              Cancelar
            </Button>
            <Button
              type='submit'
              form={TENANT_FORM_ID}
              disabled={isSavingTenant || !canSubmitTenant}
            >
              {isSavingTenant ? 'Creando tenant...' : 'Siguiente'}
            </Button>
          </>
        )}

        {currentStep === 1 && (
          <>
            <Button type='button' variant='outline' onClick={handleCancel} disabled={isSavingUser}>
              Omitir
            </Button>
            <Button
              type='submit'
              form={USER_FORM_ID}
              disabled={isSavingUser || !accountOwnerRoleId}
            >
              {isSavingUser ? 'Creando usuario...' : 'Crear usuario'}
            </Button>
          </>
        )}
      </div>
    </div>
  )
}
