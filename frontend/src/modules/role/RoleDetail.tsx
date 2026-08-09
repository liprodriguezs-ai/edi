import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { RolePermissionsEditor } from '@/modules/role/components/RolePermissionsEditor'
import { useRoleDetail } from '@/modules/role/hooks/useRoleDetail'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { Skeleton } from 'boneyard-js/react'
import { ArrowLeft, ShieldCheck } from 'lucide-react'
import { useNavigate } from 'react-router-dom'

interface RoleDetailProps {
  roleId: string
}

export function RoleDetail({ roleId }: RoleDetailProps) {
  const { role, isLoading, error, notFound, refresh } = useRoleDetail(roleId)
  const navigate = useNavigate()

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
      <div className='flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between'>
        <div className='flex items-center gap-3'>
          <Button variant='ghost' size='icon' onClick={() => navigate('/role')}>
            <ArrowLeft className='h-4 w-4' />
          </Button>

          <Skeleton name='role-detail-header' loading={isLoading}>
            <div>
              <div className='flex items-center gap-2'>
                <ShieldCheck className='text-primary h-5 w-5' />
                <h1 className='text-xl font-semibold'>
                  {String(role?.description ?? '') || role?.name}
                </h1>
                {role?.system && (
                  <Badge variant='secondary' className='text-xs'>
                    Sistema
                  </Badge>
                )}
              </div>
              <p className='text-muted-foreground text-sm'>{role?.name}</p>
            </div>
          </Skeleton>
        </div>
      </div>

      <RolePermissionsEditor roleId={roleId} />
    </div>
  )
}
