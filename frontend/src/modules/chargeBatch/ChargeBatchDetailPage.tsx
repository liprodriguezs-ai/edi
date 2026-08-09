import { ChargeBatchDetail } from '@/modules/chargeBatch/ChargeBatchDetail'
import { Navigate, useParams } from 'react-router-dom'

export const ChargeBatchDetailPage = () => {
  const { id } = useParams<{ id: string }>()
  if (!id) return <Navigate to="/charge-batch" replace />
  return <ChargeBatchDetail batchId={id} />
}
