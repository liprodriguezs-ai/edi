import { FollowUpDetail } from '@/modules/followUp/FollowUpDetail'
import { Navigate, useParams } from 'react-router-dom'

export function FollowUpDetailPage() {
  const { id } = useParams<{ id: string }>()

  if (!id) return <Navigate to="/follow-up" replace />

  return <FollowUpDetail followUpId={id} />
}
