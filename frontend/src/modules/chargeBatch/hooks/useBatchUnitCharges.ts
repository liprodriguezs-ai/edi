import { ChargeResource } from '@/modules/charge/services/ChargeResource'
import { keepPreviousData, useQuery } from '@tanstack/react-query'

/**
 * Tamaño de página para la tabla de cargos por unidad dentro del detalle
 * de un lote. 25 filas es un compromiso razonable entre visibilidad y
 * velocidad de respuesta del servidor.
 */
const BATCH_CHARGES_PAGE_SIZE = 25

/**
 * Cargos de un lote con paginación server-side para la tabla por unidad.
 *
 * La queryKey incluye el número de página → cada página tiene su propia
 * entrada de caché. `placeholderData: keepPreviousData` evita el flash vacío
 * al cambiar de página.
 *
 * El filtro `is_cancelled = false` hace que el total del meta refleje solo
 * los cargos activos mostrados (excluye cancelados).
 *
 * Comparte el prefijo ['charges'] con useChargeBatchStats → invalidar tras
 * activar el lote refresca ambas queries.
 */
export function useBatchUnitCharges(batchId: string, enabled = true, page = 1) {
  return useQuery({
    queryKey: ['charges', 'by-batch', batchId, page],
    queryFn: async () => {
      const response = await ChargeResource.search({
        page,
        limit: BATCH_CHARGES_PAGE_SIZE,
        filters: [
          { field: 'charge_batch_id', operator: '=', value: batchId },
          { field: 'is_cancelled', operator: '=', value: false },
        ],
        includes: [{ relation: 'unit' }, { relation: 'charge_status' }],
        sort: [{ field: 'due_date', direction: 'asc' }],
      })
      const total = response.meta?.total ?? response.total ?? response.data.length
      return {
        charges: response.data,
        total,
        page,
        pageSize: BATCH_CHARGES_PAGE_SIZE,
        lastPage: Math.max(1, Math.ceil(total / BATCH_CHARGES_PAGE_SIZE)),
      }
    },
    enabled: enabled && Boolean(batchId),
    placeholderData: keepPreviousData,
  })
}
