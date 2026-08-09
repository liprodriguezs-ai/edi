---
name: charge-policy-cockpit
description: ChargePolicy unified cockpit (config + generated batches) review — patterns, smells, and the as-never avoidance
metadata:
  type: pattern
---

# ChargePolicy Cockpit (unified config + lotes + cargos)

Cockpit = single screen replacing the old policy-form + batch-views split.
`ChargePolicyCockpitPage` orchestrates: header summary + 2 columns (left
`PolicyConfigForm` editable config, right `CockpitBatchList` for existing rule
OR `CockpitDirectScope` for new direct rule). New extracted shared components:
`chargeBatch/components/BatchUnitChargesTable` + `chargeRows.ts` (`toChargeRows`
split out for react-refresh/only-export-components). `ChargeBatchDetailPage`
reuses both; React Query dedupes by shared `queryKey ['charges','by-batch',id]`.

## What's done well (reinforce)
- Avoided the recurring `as never` show() cast by typing `ChargePolicyResource`
  with `TForm = Record<string, unknown>`, so `show(id, {include})` typechecks
  cleanly. ChargeBatchDetailPage still uses `as never` (TForm is the typed form).
- `useBaseCrud.create` returns `data.data` with the real backend UUID, so
  `navigate('/charge-policy/'+result.id)` after create is safe (no 404).
- Sheet (ChargeConcept identity) and ChargeBatchFormPage NOT broken — entry
  point `/charge-concept/:id/policies` intact; `/charge-batch/new` reachable.
- boneyard Skeleton for loading, displayFormatDate, StatusPill atom (moved to
  shared/components/atoms — old treasurerPanel copy now re-exports), Spanish UI,
  proper loading/empty/error tri-state in both CockpitBatchList and
  BatchUnitChargesTable.

## Smells / findings observed (cockpit slice, 2026-06-25)
- CockpitBatchList expandable rows: collapsed drill uses grid-rows-[0fr] +
  overflow-hidden but does NOT set `inert`/`hidden` — the always-rendered
  "Abrir cobro completo →" Link stays in keyboard tab order while visually
  collapsed. The charges table itself is deferred (enabled={isOpen}) so its
  buttons don't leak, but the Link does. MINOR a11y.
- ChargePolicyListPage passes `onClick={e.stopPropagation()}` on the actions
  TableCell but does NOT pass `onRowClick` to ResponsiveDataTable → dead
  stopPropagation (rows aren't clickable). MINOR.
- Cockpit header shows `Periódico · automáticamente` (periodicityCadenceLabel
  default) before a periodicity is picked on a NEW periodic rule. Cosmetic.
- DIRECT collection_mode still requires periodicity_id (schema min(1)) — a
  one-shot direct charge forcing a periodicity choice is a UX oddity worth
  confirming against backend contract (may be intentional: backend stores it).

## tsc/eslint/test baseline at review time
- 4 PRE-EXISTING tsc errors outside cockpit: login-form.tsx x2 (unused
  OAuthButtons/handleOAuth), access/AccessLogListPage.tsx:171, access/
  VisitorListPage.tsx:146. None in cobranza.
- eslint cockpit files: 0 errors; only react-hooks/incompatible-library warning
  on RHF watch() in ChargeBatchFormPage (library limitation, not a defect).
- chargePolicy + chargeBatch: 4 files, 40 tests green.
