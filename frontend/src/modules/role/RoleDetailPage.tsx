import { RoleDetail } from '@/modules/role/RoleDetail'
import { Navigate, useParams } from 'react-router-dom'

export function RoleDetailPage() {
  const { id } = useParams<{ id: string }>()

  if (!id) return <Navigate to="/role" replace />

  return <RoleDetail roleId={id} />
}
