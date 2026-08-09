import type { ChargeBatchSummary } from '@/modules/chargeBatch/services/ChargeBatchResource'
import { ChargeBatchResource } from '@/modules/chargeBatch/services/ChargeBatchResource'
import { getActiveCurrency } from '@/shared/utils/currency'
import { formatMinorUnits, type IMoney } from '@/shared/utils/money'
import { useQuery } from '@tanstack/react-query'

/** Money en cero para el estado previo a que resuelva el query de resumen. */
function zeroMoney(): IMoney {
  return { amount: '0', currency: getActiveCurrency(), formatted: formatMinorUnits(0) }
}

const DEFAULT_STATS: ChargeBatchSummary = {
  expected: zeroMoney(),
  paid: zeroMoney(),
  outstanding: zeroMoney(),
  partial_paid: zeroMoney(),
  unpaid_outstanding: zeroMoney(),
  total: 0,
  completed: 0,
  partial: 0,
  unpaid: 0,
  overdue: 0,
}

/**
 * KPIs agregados del lote obtenidos desde el endpoint de resumen del servidor.
 * Elimina la dependencia de una carga parcial de cargos (tope 100) y calcula
 * todos los totales en el backend sobre el conjunto completo.
 *
 * queryKey comparte el prefijo ['charges'] → invalidar tras activar también
 * refresca este resumen (misma invalidación que usa ChargeBatchDetail).
 *
 * `enabled` (default true) permite diferir el resumen hasta que el lote base
 * haya cargado, evitando una llamada al summary cuando el lote no existe (404).
 */
export function useChargeBatchStats(batchId: string, enabled = true) {
  const { data: stats = DEFAULT_STATS } = useQuery({
    queryKey: ['charges', 'batch', batchId, 'summary'],
    queryFn: () => ChargeBatchResource.summary(batchId),
    enabled: Boolean(batchId) && enabled,
  })

  return { stats }
}
