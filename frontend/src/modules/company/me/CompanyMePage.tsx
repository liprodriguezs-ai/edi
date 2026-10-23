import { CompanyForm } from '@/modules/company/CompanyForm'
import { useCompanyMe } from '@/modules/company/me/useCompanyMe'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useRoles } from '@/shared/hooks/useRoles'
import { toast } from '@/shared/hooks/useToast'
import { ERoleUserSlug } from '@/shared/interfaces/Entity'
import { Skeleton } from 'boneyard-js/react'
import { memo } from 'react'
import { useNavigate } from 'react-router-dom'

const ROLES_CAN_EDIT_COMPANY: ERoleUserSlug[] = [
  ERoleUserSlug.ACCOUNT_OWNER,
  ERoleUserSlug.ADMINISTRATOR,
]

function CompanyMePageInner() {
  const { company, isLoading, error, isNotFound, refetch } = useCompanyMe()
  const { hasAnyRole } = useRoles()
  const canEdit = hasAnyRole(ROLES_CAN_EDIT_COMPANY)
  const navigate = useNavigate()

  if ((error || isNotFound) && !company) {
    return (
      <EntityNotFound
        variant={isNotFound ? 'notFound' : 'error'}
        description={isNotFound ? 'No se encontró la configuración de tu empresa.' : (error ?? undefined)}
        backTo='/'
        backLabel='Ir al inicio'
      />
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Mi empresa"
        description={
          canEdit
            ? 'Configura los datos y la identidad de tu empresa.'
            : 'Datos de tu empresa. Solo el titular o el administrador pueden modificarlos.'
        }
      />

      <Skeleton name="company-form" loading={isLoading && !company}>
        {company && (
          <CompanyForm
            mode={CrudMode.EDIT}
            entity={company}
            readonly={!canEdit}
            onSuccess={() => {
              refetch()
              toast.success('Empresa actualizada correctamente')
            }}
            onCancel={() => navigate(-1)}
          />
        )}
      </Skeleton>

    </div>
  )
}

export const CompanyMePage = memo(CompanyMePageInner)
