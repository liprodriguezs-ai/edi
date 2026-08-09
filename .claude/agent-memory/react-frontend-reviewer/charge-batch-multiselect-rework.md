---
name: charge-batch-multiselect-rework
description: Findings from the single→multiselect rework for charge batch + charge policy scope selectors (target_unit_type_ids, target_tower_ids, target_feature_ids)
metadata:
  type: project
---

# Charge Batch / Policy — multiselect rework (reviewed 2026-06-30)

## What changed

`target_unit_type_id` (string) → `target_unit_type_ids` (string[])
`target_tower_id` (string) → `target_tower_ids` (string[])
`target_feature` (string) → `target_feature_ids` (string[])

Both `chargeBatch/schema.ts` and `chargePolicy/schema.ts` updated together. `SingleTargetSelector` component (shared) updated to use `TargetCatalogMultiSelect` (new inner component replacing `TargetCatalogSelect`) with `SearchSelect multiple`.

`by_floor` and `single_unit` removed from `SINGLE_TARGET_VISIBLE_TYPES` but their branches kept alive in the component for legacy data display.

## Key findings

### WARNING — `buildPolicyDefaults` hydration path: zero test coverage
`selectorIds(selector, key)` is new code in `buildPolicyDefaults.ts` that reads arrays from the stored `target_selector` JSON. It has a string-fallback for legacy data (`string → [id]`). Neither the array happy-path nor the string fallback is tested. If the key name or type check is wrong, edit forms show empty multiselects silently. Suggested fix: add `buildPolicyDefaults.test.ts` covering at least the three cases (array, string, null).

Note: the pre-existing builder always stored arrays even with single fields, so the string fallback is defensive and unlikely to fire in practice. Still worth a test.

### selectorIds() — the pattern

```ts
function selectorIds(selector, key): string[] {
  const value = selector?.[key]
  if (Array.isArray(value)) {
    return value.filter((item): item is string => typeof item === 'string')
  }
  return typeof value === 'string' && value ? [value] : []
}
```

Pattern is correct. Compare with `selectorFirst()` (same file) which reads a single value. Both handle null/undefined selector correctly.

### AmountModeSelector / PeriodicitySelector — hidden flag pattern
Modes `FIXED_OPTIONAL`, `RANGE`, `FREE`, `BY_SHARE` and periodicities `BIWEEKLY`, `BIMONTHLY` marked `hidden: true` on their static card config, filtered with `.filter(c => !c.hidden)`. Pattern: keep legacy data paths alive but don't offer in UI. Re-activate by removing `hidden`. Clean and reversible.

### Accessibility — radio-as-cards: CORRECT
- `htmlFor={optionId}` on label, `id={optionId}` on RadioGroupItem: label click activates radio.
- Decorative icons have `aria-hidden`.
- Hint text lives inside `<label>` — screen readers announce it as part of the radio label.
- `SearchSelect` maps `id` prop to `inputId` (react-select) — `htmlFor` on FieldLabel correctly points to the combobox input.
- Keyboard navigation handled by Radix RadioGroup.

### CSS variables used — all defined
`--ink-3`, `--green-deep`, `--green-bg` all defined in `src/index.css` for light and dark mode.

### Infra cost: no regression
Catalogs arrive as props to `SingleTargetSelector` — no new `useCatalogs` call inside the component.

### useWatch positional destructuring (pre-existing, not introduced here)
`ChargeRuleFormPage.tsx` destructs a 7-element `useWatch` array positionally. Fragile if someone adds/reorders names without updating destructuring. Consider named-object form with individual `useWatch` calls.

## Conventions confirmed
- `TargetCatalogItem { id: string; name?: string }` is defined locally in `SingleTargetSelector.tsx` — same recurring pattern as `CatalogItem` in other modules. Should eventually go to `shared/interfaces/catalog.types.ts`.
- `superRefine` pattern for conditional-required fields in schema is consistent: `need(!values.field?.length, 'field', 'mensaje')`.
- `PolicyTargetFields = Pick<ChargePolicyFormValues, ...>` keeps the type surface minimal for the preview selector.
