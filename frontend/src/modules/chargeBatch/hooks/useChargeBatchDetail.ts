import type { IChargeBatch } from '@/modules/chargeBatch/schema'
import { ChargeBatchResource } from '@/modules/chargeBatch/services/ChargeBatchResource'
import { ConfigurationResource } from '@/modules/configuration/services/ConfigurationResource'
import { useEntityDetail } from '@/shared/hooks/useEntityDetail'
import { useQuery } from '@tanstack/react-query'

/**
 * Lote (con policy + concepto + estado) y settings de cobranza del clúster,
 * la data base del detalle de cobro.
 */
export function useChargeBatchDetail(batchId: string) {
  const {
    entity: batch,
    isLoading,
    isError,
    isSuccess,
    notFound,
    refetch,
  } = useEntityDetail<IChargeBatch>({
    queryKey: ['charges', 'batch', batchId],
    queryFn: () => ChargeBatchResource.show(batchId, { include: 'policy,policy.concept,status' }),
    enabled: Boolean(batchId),
  })

  const { data: collectionSettings } = useQuery({
    queryKey: ['config', 'collection-settings'],
    queryFn: () => ConfigurationResource.getCollectionSettings(),
    staleTime: 5 * 60 * 1000,
    enabled: isSuccess,
  })

  return { batch, isLoading, isError, isSuccess, notFound, refetch, collectionSettings }
}
