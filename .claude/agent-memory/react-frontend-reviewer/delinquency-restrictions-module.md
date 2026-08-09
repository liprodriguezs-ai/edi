---
name: delinquency-restrictions-module
description: Delinquency-restrictions frontend review (2026-07-14) — per-unit gating verified, useMyRestrictions module placement, isLoading gap, BulkIntakeForm useWatch perf, guard-note a11y
metadata:
  type: project
---

## Delinquency restrictions — frontend review (2026-07-14)

Scope: `useMyRestrictions` (new `modules/collections/`), `DelinquencyConfigSection`,
`ReserveAmenityWizard`, `VisitorFormModal`, `usePackageCatalogOptions`,
`PackageForm`, `BulkIntakeForm` + 7 new test files.
Verified green: typecheck clean, 15/15 new tests pass, scoped lint = pre-existing warnings only.

### Hard contracts — all three VERIFIED HOLDING
- Entry points stay visible+enabled: `AmenityBrowseDetailPage.tsx:133` ("Reservar") and
  `VisitorListPage.tsx:465` ("Registrar visita") untouched. Notice lives inside wizard/modal.
- PER-UNIT gating genuinely per-unit in the COMPONENTS, not just the hook:
  `ReserveAmenityWizard.tsx:222` (`unitId ? unitState(unitId) : undefined`) and
  `VisitorFormModal.tsx:82` (`watch('unit_id')` → `unitState(selectedUnitId)`).
  Both read the SELECTED unit only. Unit A current + unit B overdue → A still works.
- Guard note copy is exactly "Esta unidad no puede recibir paquetes." (PackageForm.tsx:162,
  BulkIntakeForm.tsx:164) — no amount, no debt wording. Correct.

### Open findings (not yet fixed)
- **`isLoading` returned but never consumed** by any of the 3 consumers. During the fetch
  window `restriction` is undefined → notice absent + buttons enabled → resident gets a raw
  403 instead of the friendly notice. Worst case: single-unit resident in
  `ReserveAmenityWizard` (restriction evaluated at open, no interaction to mask latency).
  Same shape for `restrictedUnitIds` (empty Set while in-flight).
- **`BulkIntakeForm.tsx:66`** — `useWatch({ control, name: 'packages' })` subscribes to the
  WHOLE field array. `recipient_name`/`tracking_number`/`storage_location` were uncontrolled
  (`register`); now every keystroke in any row re-renders the entire form. Fix: watch only
  `packages.${i}.unit_id` paths.
- **Guard-note a11y**: `PackageForm.tsx:161` / `BulkIntakeForm.tsx:163` are plain `<p>` — no
  `role="alert"`, unlike `ReserveAmenityWizard.tsx:339` and `VisitorFormModal.tsx:180` which
  DO use it. None of the 3 wire `aria-describedby` from the unit control to the notice
  (`components/ui/field.tsx` does NOT auto-wire it — verified).
- **`role="alert"` mounted-already-present gap**: an alert present on the live region's first
  render is generally NOT announced. Hits the single-unit restricted resident in the wizard.
  A→B radio switch DOES announce (insertion into existing DOM).

### Module placement question (raised, undecided)
`src/modules/collections/` is a BRAND-NEW module holding exactly one hook, with ZERO
consumers inside itself — both consumers are `access` and `reservation`. Per CLAUDE.md the
frontend cobranza cluster is `{charge,chargeBatch,chargeConcept,chargePolicy,payment}`;
there is no `collections` frontend module. This creates a 46th module for one cross-cutting
hook. Better home: `shared/hooks/` or `modules/payment/hooks/`.

### Test-quality gaps found
- `expect(mockCreate).not.toHaveBeenCalled()` AFTER clicking a *disabled* button passes
  trivially — it never exercises the in-handler guard (`if (isVisitorAccessRestricted) return`).
  Present in VisitorFormModal.test.tsx:128, PackageForm.test.tsx:107, BulkIntakeForm.test.tsx:96.
  To prove the guard you must `fireEvent.submit(form)`.
- `BulkIntakeForm.test.tsx:81` title claims "blocks while ANY row" but only ever renders ONE
  row (never clicks "+"). The multi-row aggregate is untested.
- `DelinquencyConfigSection.test.tsx:62` voseo regex over `queryByText` is decorative — it
  would pass on almost any copy.

### Good patterns to reuse
- `usePackageCatalogOptions.ts:52-57` — restricted-units query rides `enabled: unitsEnabled`,
  the SAME lazy trigger as the unit dropdown. Zero fetch on list mount. Textbook infra-cost.
- Thin-wrapper `if (!isOpen) return null` (`VisitorFormModal.tsx:46`) to stop a hook firing on
  every parent list render. Wrapper has zero hooks before the return → no Rules-of-Hooks issue.
- `DelinquencyConfigSection` improves on `PackageConfigSection`: typed key union in
  `boolSetting` instead of `key: string` + `as keyof`.
- Radix `Select` local stub in tests (PackageForm/BulkIntakeForm) — the codebase has no
  precedent for driving real Radix internals in jsdom; this stub is the pragmatic answer.

### Confirmed NOT a regression
`VisitorFormModal.tsx:81` React Compiler "Compilation Skipped: Use of incompatible library"
(`watch()`) — pre-existing, was at line 70 before this change (verified by linting stashed HEAD).
`useWatch` (used in PackageForm/BulkIntakeForm) is the compiler-safe API.

### Style precedent note
`text-[12px] font-medium text-amber-600` for the guard note follows `AccessNowPage.tsx:129`
exactly — consistent with the project, though amber-600 on white is ~3.2:1 (WCAG AA needs
4.5:1 for normal text) and these usages lack the `dark:text-amber-400` variant that
`CancelSubscriptionModal`/`AuditDiffModal` carry.
