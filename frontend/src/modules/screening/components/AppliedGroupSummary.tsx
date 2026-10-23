import { Badge } from '@/components/ui/badge'
import type { IScreening } from '@/modules/screening/schema'
import {
  ageGroupNumberLabel,
  ageGroupRangeLabel,
  hasCorrectedAgeSubstitution,
  isAgeGroupChosen,
} from '@/modules/screening/utils/ageGroupCopy'

interface AppliedGroupSummaryProps {
  screening: IScreening
  /** Group code from the item bank, used while the relation is not loaded. */
  fallbackCode?: string
  /** Show the group's test-kit "Material" (apply page); the detail omits it. */
  showMaterials?: boolean
}

/**
 * S7 — the applied group, shown prominently as the legacy did ("Grupo N" + its
 * age range), flagged "A elección" when the evaluator picked it (S2) and
 * explaining a corrected-age substitution otherwise.
 */
export function AppliedGroupSummary({ screening, fallbackCode, showMaterials = false }: AppliedGroupSummaryProps) {
  const group = screening.applied_age_group
  const groupLabel = group ? ageGroupNumberLabel(group) : (fallbackCode ?? '—')
  const range = group ? ageGroupRangeLabel(group) : null
  const materials = showMaterials ? group?.materials_note?.trim() : null
  const chosen = isAgeGroupChosen(screening)
  // The chronological group is wrong for premature children (age mode would
  // apply the corrected-age group); it is only the fallback for screenings
  // created before the server recorded the age-derived group.
  const ageDerivedGroup = screening.age_derived_age_group ?? screening.age_group

  return (
    <div className="space-y-1.5">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-lg font-semibold tracking-tight" data-testid="applied-group">
          {groupLabel}
        </span>
        {range && <span className="text-sm text-muted-foreground">{range}</span>}
        {chosen && <Badge variant="secondary">A elección</Badge>}
      </div>

      {chosen && (
        <p className="text-xs text-muted-foreground">
          Prueba EDI a elección: el grupo lo eligió quien aplica
          {ageDerivedGroup?.ordinal != null &&
            ` (por edad correspondía el ${ageGroupNumberLabel(ageDerivedGroup)})`}
          .
        </p>
      )}

      {hasCorrectedAgeSubstitution(screening) && (
        <p className="border-l-2 border-primary pl-2 text-xs text-muted-foreground">
          Grupo sustituido por edad corregida{' '}
          <span className="font-mono text-foreground">
            {screening.age_group?.name} → {screening.applied_age_group?.name}
          </span>
        </p>
      )}

      {materials && (
        <details className="max-w-prose text-sm">
          <summary className="cursor-pointer text-muted-foreground">Material para este grupo</summary>
          <p className="mt-1">
            <span className="font-medium">Material:</span> {materials}
          </p>
        </details>
      )}
    </div>
  )
}
