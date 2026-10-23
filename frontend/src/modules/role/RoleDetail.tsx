import { RolePermissionsEditor } from '@/modules/role/components/RolePermissionsEditor'
import { useRoleDetail } from '@/modules/role/hooks/useRoleDetail'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { Skeleton } from 'boneyard-js/react'
import { ShieldCheck } from 'lucide-react'

interface RoleDetailProps {
  roleId: string
}

export function RoleDetail({ roleId }: RoleDetailProps) {
  const { role, isLoading, error, notFound, refresh } = useRoleDetail(roleId)

  if (error && !isLoading) {
    return (
      <EntityNotFound
        variant={notFound ? 'notFound' : 'error'}
        title={notFound ? 'Rol no encontrado' : undefined}
        backTo='/role'
        backLabel='Volver a roles'
        onRetry={notFound ? undefined : refresh}
      />
    )
  }

  return (
    <div className='space-y-6'>
      <Skeleton name='role-detail-header' loading={isLoading}>
        <PageHeader
          back={{ to: '/role', label: 'Volver a roles' }}
          title={
            <span className='flex items-center gap-2'>
              <ShieldCheck className='size-5 text-primary' aria-hidden />
              {String(role?.description ?? '') || role?.name}
            </span>
          }
          description={role?.name}
          meta={role?.system ? <StatusPill tone='n'>Sistema</StatusPill> : undefined}
        />
      </Skeleton>

      <RolePermissionsEditor roleId={roleId} />
    </div>
  )
}
