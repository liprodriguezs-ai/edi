import type {
  IAgeGroup,
  IAreaScoringRule,
  IGlobalResultMessage,
  IGlobalScoringRule,
  IHeadCircumferenceReference,
  IInstrumentVersion,
  IRiskFactor,
  IScreeningItem,
} from '@/modules/instrument/schema'
import {
  AgeGroupResource,
  AreaScoringRuleResource,
  GlobalResultMessageResource,
  GlobalScoringRuleResource,
  HeadCircumferenceReferenceResource,
  InstrumentVersionResource,
  RiskFactorResource,
  ScreeningItemResource,
} from '@/modules/instrument/services/InstrumentResource'
import type { PaginatedResponse } from '@/shared/interfaces/Entity'
import { useQuery } from '@tanstack/react-query'

/**
 * Read-only server-state (TanStack Query) for the instrument inspector.
 *
 * The backend registers ONLY index/show for every instrument resource
 * (design D23 / REQ-INS-014) — Orion's richer query-filter engine
 * (`filters[]`) is wired ONLY into its `/search` action, which this module
 * deliberately does not register. Server-side filtering is therefore
 * unavailable: `fetchAllPages` walks every page of the (small, bounded)
 * content set — max 451 items / version, 902 total — and each hook filters
 * client-side by the natural FK it needs. This is instrument CONTENT for an
 * admin-only inspector, fetched lazily per tab, not a catalog dropdown —
 * it does not fall under the "no eager catalog loading" anti-pattern.
 */

const ORION_MAX_LIMIT = 100

async function fetchAllPages<T extends Record<string, unknown>>(
  resource: { list: (params: Record<string, unknown>) => Promise<PaginatedResponse<T>> },
  params: Record<string, unknown> = {}
): Promise<T[]> {
  const all: T[] = []
  let page = 1

  while (true) {
    const response = await resource.list({ ...params, limit: ORION_MAX_LIMIT, page })
    all.push(...response.data)

    const meta = response.meta
    if (!meta || meta.current_page >= meta.last_page) break
    page += 1
  }

  return all
}

export function useInstrumentVersions() {
  return useQuery({
    queryKey: ['instrument', 'versions'],
    queryFn: () => fetchAllPages<IInstrumentVersion>(InstrumentVersionResource, { include: 'instrument' }),
  })
}

export function useInstrumentVersion(versionId: string | undefined) {
  return useQuery({
    queryKey: ['instrument', 'version', versionId],
    queryFn: () => InstrumentVersionResource.show(versionId as string, { include: 'instrument' }),
    enabled: Boolean(versionId),
  })
}

export function useAgeGroups(versionId: string | undefined) {
  return useQuery({
    queryKey: ['instrument', 'age-groups', versionId],
    queryFn: async () => {
      const all = await fetchAllPages<IAgeGroup>(AgeGroupResource, { include: 'alternate' })
      return all
        .filter((g) => g.instrument_version_id === versionId)
        .sort((a, b) => a.ordinal - b.ordinal)
    },
    enabled: Boolean(versionId),
  })
}

export function useScreeningItemsByGroup(groupId: string | undefined) {
  return useQuery({
    queryKey: ['instrument', 'screening-items', groupId],
    queryFn: async () => {
      const all = await fetchAllPages<IScreeningItem>(ScreeningItemResource, {
        include: 'options,options.result_color,development_area,application_position',
      })
      return all
        .filter((i) => i.age_group_id === groupId)
        .sort((a, b) => a.sort_order - b.sort_order)
    },
    enabled: Boolean(groupId),
  })
}

export function useAreaScoringRules(versionId: string | undefined) {
  return useQuery({
    queryKey: ['instrument', 'area-scoring-rules', versionId],
    queryFn: async () => {
      const all = await fetchAllPages<IAreaScoringRule>(AreaScoringRuleResource, {
        include: 'age_group,development_area',
      })
      return all.filter((r) => r.instrument_version_id === versionId)
    },
    enabled: Boolean(versionId),
  })
}

export function useGlobalScoringRules(versionId: string | undefined) {
  return useQuery({
    queryKey: ['instrument', 'global-scoring-rules', versionId],
    queryFn: async () => {
      const all = await fetchAllPages<IGlobalScoringRule>(GlobalScoringRuleResource, {
        include: 'age_group,result_color,message',
      })
      return all
        .filter((r) => r.instrument_version_id === versionId)
        .sort((a, b) => a.sequence - b.sequence)
    },
    enabled: Boolean(versionId),
  })
}

export function useRiskFactors(versionId: string | undefined) {
  return useQuery({
    queryKey: ['instrument', 'risk-factors', versionId],
    queryFn: async () => {
      const all = await fetchAllPages<IRiskFactor>(RiskFactorResource)
      return all
        .filter((r) => r.instrument_version_id === versionId)
        .sort((a, b) => a.sort_order - b.sort_order)
    },
    enabled: Boolean(versionId),
  })
}

export function useHeadCircumferenceReferences(versionId: string | undefined) {
  return useQuery({
    queryKey: ['instrument', 'head-circumference', versionId],
    queryFn: async () => {
      const all = await fetchAllPages<IHeadCircumferenceReference>(HeadCircumferenceReferenceResource, {
        include: 'sex',
      })
      return all
        .filter((r) => r.instrument_version_id === versionId)
        .sort((a, b) => a.age_months - b.age_months)
    },
    enabled: Boolean(versionId),
  })
}

export function useGlobalResultMessages(versionId: string | undefined) {
  return useQuery({
    queryKey: ['instrument', 'global-result-messages', versionId],
    queryFn: async () => {
      const all = await fetchAllPages<IGlobalResultMessage>(GlobalResultMessageResource, {
        include: 'result_color',
      })
      return all.filter((m) => m.instrument_version_id === versionId)
    },
    enabled: Boolean(versionId),
  })
}
