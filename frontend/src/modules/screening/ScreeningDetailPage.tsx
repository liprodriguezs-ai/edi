import { ScreeningDetail } from '@/modules/screening/ScreeningDetail'
import { Navigate, useParams } from 'react-router-dom'

export function ScreeningDetailPage() {
  const { id } = useParams<{ id: string }>()

  if (!id) return <Navigate to="/screening" replace />

  return <ScreeningDetail screeningId={id} />
}
