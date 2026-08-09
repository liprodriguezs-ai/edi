import type { DelinquencyUnitState } from '@/modules/collections/schema'
import { apiClient } from '@/shared/api/apiClient'
import { useQuery } from '@tanstack/react-query'
import { useCallback } from 'react'

/**
 * Wire envelope of `GET /my-restrictions`. The top-level `restricted` field
 * the backend computes (`DelinquencyRestrictionController::myRestrictions`)
 * is a convenience OR across the caller's units — deliberately NOT surfaced
 * by this hook. Consumers must always gate through `unitState(unitId)`, never
 * this aggregate (R-DELQ-1 — the exact bug this design fixes).
 */
export interface MyRestrictionsResponse {
  notice: string
  units: DelinquencyUnitState[]
}

async function getMyRestrictions(): Promise<MyRestrictionsResponse> {
  // GET /my-restrictions returns a flat top-level payload (no `{ data }`
  // envelope) — see design §6.2 / DelinquencyRestrictionController::myRestrictions.
  const { data } = await apiClient.get<MyRestrictionsResponse>('/my-restrictions')
  return data
}

/**
 * Resident-facing delinquency state for the CALLER's own units (design §6.2 /
 * §8.2). Meant to be called ONLY inside components that mount when a guarded
 * action starts (the reservation wizard / visitor modal bodies), never at
 * list/page scope — so the request fires on action initiation, not on every
 * page load (infra-cost rule). The shared query key dedupes across consumers.
 *
 * Gating MUST stay per unit, never an aggregate: `unitState(unitId)` looks up
 * ONLY the selected unit's entry. A resident holding one current unit and one
 * overdue unit must keep acting freely on the current one (R-DELQ-1).
 */
export function useMyRestrictions() {
  const { data, isLoading } = useQuery({
    queryKey: ['my-restrictions'],
    queryFn: getMyRestrictions,
    staleTime: 60_000,
  })

  const unitState = useCallback(
    (unitId: string): DelinquencyUnitState | undefined =>
      data?.units.find((unit) => unit.unit_id === unitId),
    [data]
  )

  return {
    notice: data?.notice,
    unitState,
    isLoading,
  }
}
