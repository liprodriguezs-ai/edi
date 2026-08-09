---
name: charge-rule-form-2col
description: ChargeRuleFormPage 2-column cockpit refactor review — watch() perf, nested-ternary, concept-picker conditional logic, surcharge field array
metadata:
  type: pattern
---

# ChargeRuleFormPage — 2-column cockpit (config left / preview right)

Reviewed 2026-06-25. Replaces the policy form/modal with a single 2-col page:
left `<form id="charge-rule-form">` (PolicyConfigForm + TargetTypeSection +
SurchargeRulesSection), right `<aside>` PolicyPreviewPanel (read-only timeline).
Save button lives OUTSIDE the form in the header, associated via
`form="charge-rule-form"` (valid HTML5, works). New components: ChargeConceptSelect,
PolicyPreviewPanel, SurchargeRulesSection.

## Verified correct (don't re-flag)
- `needsConceptPicker = !isEdit && !conceptId` handles all 3 cases. When picker is
  hidden the Controller is unmounted but `charge_concept_id` still validates because
  RHF keeps the value (shouldUnregister default false) and zodResolver validates the
  full getValues() object. Create sends concept; update OMITS it (immutable). OK.
- form= attribute on out-of-form header save button: correct, works in same commit.
- 2-col: `grid-cols-1 lg:grid-cols-[minmax(0,1fr)_360px]` + `aside lg:sticky lg:top-6
  lg:self-start`. self-start is the key that makes sticky work in a grid item. minmax(0,1fr)
  prevents left overflow. Collapses to 1 col below lg (preview stacks under form). OK.
- Money/% conversions symmetric & tested: pesos↔minor (×/÷100), %→basis points
  (percentToBasisPoints ×100 / basisPointsToPercent ÷100). surcharge_value is RAW int
  (bp in PERCENT, minor units in FIXED); cap_amount always minor units. 31 tests green.
- `effectiveDueSoonDays` uses `dueSoonOverride != null` (NOT truthiness) so a 0 override
  is honored. Good pattern — reinforce.
- useFieldArray keyed by field.id (stable); per-row useWatch for $/%% prefix.
- ChargePolicyAllListPage FIXES the dead-stopPropagation smell from ChargePolicyListPage:
  it DOES pass onRowClick + stopPropagation on the actions cell. useShallow selector used.

## Findings (this review)
- WARNING perf: `buildPolicyTargetSelector(watch())` at ChargeRuleFormPage.tsx:195 uses
  full `watch()` (subscribes to ALL fields) → whole page (unmemoized heavy children:
  radio cards, field array, timeline) re-renders on every keystroke in ANY field,
  including `name` which is otherwise unwatched. Also computed in edit mode where the
  result is unused (preview hidden when isEdit). Fix: useWatch scoped to target_* fields
  + guard `!isEdit`. (BatchPreview new-object ref is harmless — React Query hashes queryKey.)
- WARNING skill: nested ternary for `vigencia` at ChargeRuleFormPage.tsx:239-244 violates
  CLAUDE.md anti-pattern / react-frontend-architecture strict rule 3 (no nested ternaries). The
  button-label nested ternary (line 257) ALSO technically violates but mirrors the skill's
  own canonical DocumentForm/FormModal examples, so it's de-facto sanctioned; vigencia
  (with template literals) is the one to refactor to a helper.
- SUGGESTION: eventual-EDIT preview is dead — lifecycleMilestones guards `if (isEdit ||
  !issuedAt || !dueDate)` (line 200) and parametric guards `if (isEventual ...)`, so editing
  an eventual policy always shows the empty hint despite dates being present (disabled).
- SUGGESTION: handleValidationError knownFields (lines 110-120) omits surcharge_rules.* and
  target_* → server 422 on those falls through to generic toast (no inline mapping).
- SUGGESTION a11y: AmountModeSelector/PeriodicitySelector RadioGroup has no accessible name
  (group FieldLabel not tied via aria-labelledby). Section titles are `<p>` not h2/h3.
- SUGGESTION DRY: `numberOrNull` (SurchargeRulesSection:49) duplicates the inline setValueAs
  in PolicyConfigForm:153,170 and AmountModeSelector:97 — hoist to shared util.

## Latent (neutralized, low priority)
- reset(defaultValues) effect (lines 147-149) could wipe in-progress edits IF
  ['charge-policy',id] is invalidated mid-edit. Neutralized by global
  refetchOnWindowFocus:false + staleTime:30s, and useBaseCrud writes Zustand (not RQ cache),
  so update doesn't invalidate the policy query. Safe today.

## Baseline at review
- tsc: 0 errors in chargePolicy scope. eslint: clean (no watch() warning surfaced here,
  unlike ChargeBatchFormPage). vitest: 31 tests green (schema.test + AmountModeSelector.test).
- Zero `any` in the whole chargePolicy module. ApiError.payload.errors is Record<string,string[]>.
