import { createCrudApi } from '@/shared/api/resourceApi'
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

/**
 * Read-only wrappers over the instrument API (design §10). The backend only
 * registers index/show (REQ-INS-014) — create/update/remove/report from
 * `createCrudApi` are simply never called from this module. No mutation
 * function is exported anywhere in `instrument/`.
 */

export const InstrumentVersionResource = createCrudApi<IInstrumentVersion>({
  basePath: '/instrument_version',
})

export const AgeGroupResource = createCrudApi<IAgeGroup>({
  basePath: '/age_group',
})

export const ScreeningItemResource = createCrudApi<IScreeningItem>({
  basePath: '/screening_item',
})

export const AreaScoringRuleResource = createCrudApi<IAreaScoringRule>({
  basePath: '/area_scoring_rule',
})

export const GlobalScoringRuleResource = createCrudApi<IGlobalScoringRule>({
  basePath: '/global_scoring_rule',
})

export const RiskFactorResource = createCrudApi<IRiskFactor>({
  basePath: '/risk_factor',
})

export const HeadCircumferenceReferenceResource = createCrudApi<IHeadCircumferenceReference>({
  basePath: '/head_circumference_reference',
})

export const GlobalResultMessageResource = createCrudApi<IGlobalResultMessage>({
  basePath: '/global_result_message',
})
