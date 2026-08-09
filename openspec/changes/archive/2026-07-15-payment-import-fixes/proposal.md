# Proposal: Payment Import Fixes

- **Change**: `payment-import-fixes` | **Project**: residia-monorepo | **Store**: hybrid (this file + Engram `sdd/payment-import-fixes/proposal`)
- **Source of truth**: user-approved plan `~/.claude/plans/oye-puedes-volverle-a-gleaming-metcalfe.md`; exploration Engram `sdd/payment-import-fixes/explore` (#1552). Plan wins on divergence.

## Intent

The payment import wizard is misplaced, unreadable, and dishonest: its only entry point is an unguarded button on the Cargos screen (`ChargeListPage.tsx:356`) instead of Pagos; the module was never registered in `module_system.csv`, so it appears in no sidebar/⌘K; validation floods the UI with N identical catalog warnings (1000 rows → 1000 copies); the three summary counters all show `$validCount`; `monto` has no max cap (Excel bypasses `MoneyLimit`); the LÉEME sheet contradicts actual auto-create behavior and 3 rules leak English.

Verified — NOT broken: the payment-process refactor (earmarks, `TenantMoneyCast`, notifications) did not affect the importer. All 6 drift checks pass; FIFO intact. Zero changes there.

## Scope

### In Scope (6 changes, ~8 files)

1. Move "Importar pagos" from `ChargeListPage.tsx:356` to `PaymentListPage.tsx` header actions, wrapped in `<PermissionGuard permission='create.data_import'>`; remove unused `navigate` (import :47, decl :168).
2. Group GENERAL validation issues: mark `general` at `ReferenceResolver::buildIssue()` (7 call sites); collapse in `ImportRunner::validate()`; add OPTIONAL `count` to the issue contract; `ValidateStep.tsx` Fila cell renders "N filas". Errors + email warning stay per-row.
3. Sidebar registration: add row `a1301001-0000-4000-8000-000000000001,b266838b-4cb0-4eb1-aa76-242cf96df41a,14,Cobranza,IconCreditCard` to `backend/database/seeders/csv/module_system.csv`.
4. Honest counters: count DISTINCT new units/residents in `ImportRunner::validate()` instead of `$validCount` for all three cards.
5. Money cap: `max` rule for `monto` in `RowImporter::validate()` aligned with `MoneyLimit::MAX_MINOR_UNITS`, Spanish message.
6. Texts: fix contradictory LÉEME row 1 (`TemplateGenerator.php:68`); add Spanish messages for `tipo_unidad.string`, `nombre.string`, `apellido_paterno.string`.

### Out of Scope

- Payment process: `PaymentService`, FIFO, earmark, money casts (verified no drift — do not reopen).
- `isAutoCreateWarning()` leak (`RowImporter.php:380`), stale docblock (:151), hardcoded `'currency' => 'MXN'` (:672).
- Streaming-XLSX rewrite, chunking topology, `/import/execute` behavior.

## Capabilities

> No main `openspec/specs/` exists; sdd-spec creates fresh specs under `openspec/changes/payment-import-fixes/specs/<name>/spec.md`.

### New Capabilities

- `import-entry-and-menu`: entry button on Pagos gated by `create.data_import`; DataImport visible in sidebar/⌘K under Cobranza.
- `import-validation-feedback`: grouped general warnings (structural `general` mark + optional `count`), per-row errors preserved, distinct counters, `monto` cap, Spanish-only messages, truthful LÉEME.

### Modified Capabilities

- None.

## Approach

- Grouping is STRUCTURAL, never text-dedup: the 12 mandatory errors are byte-identical across rows; text-dedup would destroy which-rows-to-fix. Set `general` at issue creation in `ReferenceResolver` only.
- Collapse in `ImportRunner::validate()` response assembly, NOT `RowImporter` → all 11 `RowImporterValidateTest` tests stay green.
- Keep ALL errors flat in `rows` → `ImportController.php:111-125` 422 loop and `ImportValidateTest.php:262-282` unchanged. `count` optional/additive → backward-compatible.
- Never reuse `ProcessImportChunkJob::isAutoCreateWarning()` — matches preterite "se creó"; validate emits future "se creará". Zero overlap.
- Frontend button follows PaymentListPage's existing PermissionGuard pattern (:194-218). No `any`; English identifiers; Spanish UI strings only.

## Affected Areas

| Area | Impact |
|------|--------|
| `frontend/src/modules/charge/ChargeListPage.tsx` | Modified — remove button + `navigate` |
| `frontend/src/modules/payment/PaymentListPage.tsx` | Modified — add guarded button |
| `frontend/src/modules/import/schema.ts` | Modified — optional `count`/`general` on `IIssueRow` |
| `frontend/src/modules/import/components/ValidateStep.tsx` | Modified — "N filas" cell |
| `backend/Modules/DataImport/app/Services/ReferenceResolver.php` | Modified — mark 7 general issues |
| `backend/Modules/DataImport/app/Services/ImportRunner.php` | Modified — collapse + distinct counters |
| `backend/Modules/DataImport/app/Services/RowImporter.php` | Modified — `max` rule + 3 messages |
| `backend/Modules/DataImport/app/Services/TemplateGenerator.php` | Modified — LÉEME fix |
| `backend/database/seeders/csv/module_system.csv` | Modified — 1 row |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Response-shape regression breaks FE/tests | Low | `count` optional; errors flat; target ZERO backend test breaks |
| Menu row wrong system/sort | Low | Verified: system `b266838b` = Fraccionamiento; sort 14 free (Pagos=13, next 20) |
| Seeder re-run side effects | Low | Permissions/route/icon already wired; only pivot row added |

## Rollback Plan

Revert the commit(s) — code + 1 CSV row only; no migrations, no schema, no data changes. Re-run `php artisan db:seed --class=ResetPermissionTableSeeder` to drop the menu entry.

## Dependencies

- Post-deploy: `php artisan db:seed --class=ResetPermissionTableSeeder` (menu row).

## Success Criteria

- [ ] Button on Pagos (hidden without `create.data_import`); gone from Cargos.
- [ ] 1000 rows, one unknown account → ONE warning "(1000 filas)"; 3 rows missing date → 3 separate per-row errors.
- [ ] "Importación de Pagos" in sidebar under Cobranza.
- [ ] Counters show distinct new units/residents.
- [ ] Over-cap `monto` rejected in Spanish; 3 string rules answer in Spanish; LÉEME truthful.
- [ ] `php artisan test --filter=Import` green (host php, ONE process); FE import tests + typecheck + lint green.
