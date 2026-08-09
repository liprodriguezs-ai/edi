import { apiClient } from '@/shared/api/apiClient'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { useQuery } from '@tanstack/react-query'
import { useMemo } from 'react'

interface CatalogOption {
  value: string
  label: string
}

interface PackageCatalogOptions {
  unitOptions: CatalogOption[]
  carrierOptions: CatalogOption[]
  typeOptions: CatalogOption[]
  enableUnits: () => void
  enableCarriers: () => void
  enableTypes: () => void
  loadingUnits: boolean
  /** Unit ids currently blocked from package intake (design §8.3, §6.3). */
  restrictedUnitIds: Set<string>
}

interface RestrictedUnitsResponse {
  unit_ids: string[]
}

async function getRestrictedUnitIds(): Promise<string[]> {
  const { data } = await apiClient.get<RestrictedUnitsResponse>('/package/restricted-units')
  return data.unit_ids
}

/**
 * Shared lazy-catalog hook for package intake forms.
 * Catalogs load on demand (when their dropdown first opens).
 */
export function usePackageCatalogOptions(): PackageCatalogOptions {
  const {
    catalogs: unitCatalogs,
    enable: enableUnits,
    isLoading: loadingUnits,
    isEnabled: unitsEnabled,
  } = useLazyCatalogs(useMemo(() => ({ [CatalogType.UNIT]: { _sort: 'number' } }), []))
  const { catalogs: carrierCatalogs, enable: enableCarriers } = useLazyCatalogs(
    useMemo(
      () => ({ [CatalogType.PACKAGE_CARRIER]: { _sort: 'order', _filters: { is_active: true } } }),
      []
    )
  )
  const { catalogs: typeCatalogs, enable: enableTypes } = useLazyCatalogs(
    useMemo(
      () => ({ [CatalogType.PACKAGE_TYPE]: { _sort: 'order', _filters: { is_active: true } } }),
      []
    )
  )

  // Rides the SAME lazy trigger as the unit dropdown (`enableUnits`, fired from
  // `onOpenChange`) — zero fetch on list mount (infra-cost rule, design §8.3).
  const { data: restrictedUnitIdsList } = useQuery({
    queryKey: ['package-restricted-units'],
    queryFn: getRestrictedUnitIds,
    enabled: unitsEnabled,
    staleTime: 60_000,
  })

  const restrictedUnitIds = useMemo(
    () => new Set(restrictedUnitIdsList ?? []),
    [restrictedUnitIdsList]
  )

  const unitOptions = useMemo(
    () =>
      (unitCatalogs[CatalogType.UNIT] ?? []).map((u) => ({
        value: u.id,
        label:
          (u.full_identifier as string | undefined) ?? (u.number as string | undefined) ?? u.id,
      })),
    [unitCatalogs]
  )
  const carrierOptions = useMemo(
    () =>
      (carrierCatalogs[CatalogType.PACKAGE_CARRIER] ?? []).map((c) => ({
        value: c.id,
        label: (c.name as string | undefined) ?? c.id,
      })),
    [carrierCatalogs]
  )
  const typeOptions = useMemo(
    () =>
      (typeCatalogs[CatalogType.PACKAGE_TYPE] ?? []).map((t) => ({
        value: t.id,
        label: (t.name as string | undefined) ?? t.id,
      })),
    [typeCatalogs]
  )

  return {
    unitOptions,
    carrierOptions,
    typeOptions,
    enableUnits,
    enableCarriers,
    enableTypes,
    loadingUnits,
    restrictedUnitIds,
  }
}
