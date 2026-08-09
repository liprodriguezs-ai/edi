import { Button } from '@/components/ui/button'
import { CompanyForm } from '@/modules/company/CompanyForm'
import { useCompanyMe } from '@/modules/company/me/useCompanyMe'
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
      <div className="flex flex-col items-center justify-center gap-4 py-12">
        <p className="text-center text-muted-foreground">
          {isNotFound
            ? 'No se encontro la configuracion de tu empresa.'
            : error}
        </p>
        <Button variant="outline" onClick={() => navigate('/')}>
          Ir al inicio
        </Button>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-bold tracking-tight">Mi empresa</h1>
        <p className="mt-1 text-muted-foreground" id="company-me-description">
          {canEdit
            ? 'Configura los datos y la identidad de tu empresa.'
            : 'Datos de tu empresa. Solo el titular o el administrador pueden modificarlos.'}
        </p>
      </header>

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
