# Tasks: Payment Import Fixes

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 500–650 |
| 400-line budget risk | High |
| Chained PRs recommended | No |
| Suggested split | Single PR, `size:exception` |
| Delivery strategy | exception-ok |
| Chain strategy | size-exception |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|-----------------------|------------------|--------------------|
| 1 | Backend: `general` mark + text truth, collapse + honest counters, money cap, LÉEME | PR 1 (single, size:exception) | `php artisan test --filter=Import` (host php, alone) | Manual QA: import a payments file with a repeated unregistered bank account + an oversized `monto` at `http://192.168.100.136:5173/` | Revert `ReferenceResolver.php`, `ImportRunner.php`, `RowImporter.php`, `TemplateGenerator.php` — no schema/migration to undo |
| 2 | Frontend: entry-point relocation + validate UI (grouping, truncation notice) | PR 1 (same) | `pnpm test -- ValidateStep` + `pnpm typecheck` | Manual QA: Pagos header → "Importar pagos"; upload a >1000-row file to confirm the truncation notice | Revert `ChargeListPage.tsx`, `PaymentListPage.tsx`, `schema.ts`, `ValidateStep.tsx` |
| 3 | Deployment: menu CSV row + reseed | PR 1 (same) | N/A — data seed, not code | `php artisan db:seed --class=ResetPermissionTableSeeder`, then verify "Importación de Pagos" appears in sidebar/⌘K under Cobranza | Revert the CSV row + re-run the seeder to restore the prior pivot state |

## Phase 1: Backend — ReferenceResolver marking & text truth

- [x] 1.1 `backend/Modules/DataImport/app/Services/ReferenceResolver.php` — add `'general' => true` inside `buildIssue()` (~`:309`); update its `@return` docblock to `array{row: int, severity: string, column: string, message: string, general: bool}`. Satisfies REQ-03, D1. Verify: Phase 4 `ReferenceResolverTest` assertion.
- [x] 1.2 (sequential after 1.1, same file) Replace the `estado_unidad` warning at `:103-104` — remove the "la unidad se importará sin estado" claim, replace with "se usará el estado por defecto del clúster". Satisfies REQ-09, D6.b. Verify: Phase 4 message assertion.

## Phase 2: Backend — ImportRunner response assembly (ordered: 2.1 before 2.2, same file)

- [x] 2.1 `backend/Modules/DataImport/app/Services/ImportRunner.php` — implement `collapseGeneralIssues(array $allIssues): array` per D2 (dedup key `column|message`, errors untouched in row order, warnings collapsed in first-encounter order, `count` starts at 1); replace `'rows' => $allIssues` (`:78`) with the collapsed result. Satisfies REQ-04, REQ-05, REQ-06. Verify: Phase 4 collapse scenarios.
- [x] 2.2 (after 2.1) Same file — implement D3's 6-constant-read distinct counters: collect identity tuples for error-free rows during the existing loop; batch-fetch `UnitType`, `Tower`, `Unit` (FK columns, no relation calls), existing emails (case-insensitive), matched `UnitUser` links, linked users' names; compute `units_to_create`/`residents_to_create` via ID-based diff mirroring `RowImporter.php` key semantics; keep `payments_to_register = $validCount`, computed explicitly; remove dead `$blockedRows` (`:46,:55`); update `@return` docblock (`:34-41`) to add `general?: bool, count?: int`. Satisfies REQ-07. Verify: Phase 4 counter scenarios (1000 rows/50 units/3 new).

## Phase 3: Backend — RowImporter rules & TemplateGenerator text

- [x] 3.1 `backend/Modules/DataImport/app/Services/RowImporter.php` — change the `monto` rule to `['required', 'numeric', 'gt:0', 'max:'.(MoneyLimit::MAX_MINOR_UNITS / 100)]` (import `Modules\Collections\Support\MoneyLimit`); add inline comment on the pesos/centavos division; add `'monto.max' => 'El monto no puede exceder $9,999,999.99.'`. Satisfies REQ-08, D4. Verify: Phase 4 boundary test.
- [x] 3.2 Same file, `messages()` array (`:59-72`) — add `'tipo_unidad.string'`, `'nombre.string'`, `'apellido_paterno.string'` Spanish messages. Satisfies REQ-09, D6. Verify: Phase 4 non-string-value test.
- [x] 3.3 `backend/Modules/DataImport/app/Services/TemplateGenerator.php:68` — replace LÉEME row-1 second cell with the three-behavior Spanish (tú) text from D6 (auto-create+warning / torre-sección omitted / estado default fallback). Satisfies REQ-09. Verify: optional Phase 4 pin test.

## Phase 4: Backend — Tests

- [x] 4.1 Create `backend/Modules/DataImport/tests/Unit/ImportRunnerValidateTest.php` — assert: 1000 rows same unregistered account → 1 issue `count: 1000`; 3 rows missing `fecha_pago` → 3 separate entries, none `count>1`; email cross-tenant warning (2 rows) stays 2 entries; 422-path errors stay flat `rows.<n>.<col>`; 1000 rows/50 units/3 new → `units_to_create === 3`; existing-unit-not-counted drift guard. Satisfies REQ-04..REQ-07. Run: `php artisan test --filter=ImportRunnerValidateTest` (host php, alone). DONE — 6/6 passing.
- [x] 4.2 Update `backend/Modules/DataImport/tests/Unit/ReferenceResolverTest.php` (actual path — design said `tests/Feature/`, code lives in `tests/Unit/`) — assert `general === true` on all 7 catalog-warning methods (added missing coverage for `resolveUnitStatus`/`resolveTower`/`resolveSection`, which had no prior tests); assert corrected `estado_unidad` text (no "sin estado"). Satisfies REQ-03, REQ-09, D6.b. Run: `php artisan test --filter=ReferenceResolverTest` (host php, alone). DONE — 16/16 passing.
- [x] 4.3 Update `backend/Modules/DataImport/tests/Unit/RowImporterValidateTest.php` — add monto boundary cases (9999999.99 ok / 10000000.00 error, Spanish message) and the 3 new `.string` Spanish messages. Satisfies REQ-08, REQ-09. Run: `php artisan test --filter=RowImporterValidateTest` (host php, alone). DONE — 16/16 passing.
- [x] 4.4 (Optional) Pin the new LÉEME text in `TemplateGeneratorTest.php` only if that suite already pins row text elsewhere; otherwise skip. Satisfies REQ-09. SKIPPED — verified `TemplateGeneratorTest.php` does not pin the "no crea catálogos" text (only checks sheet existence + generic "Catálogos" substring); no update needed, suite still green (5/5).

## Phase 5: Backend — Menu registration (deployment)

- [x] 5.1 Append row to `backend/database/seeders/csv/module_system.csv`: `a1301001-0000-4000-8000-000000000001,b266838b-4cb0-4eb1-aa76-242cf96df41a,14,Cobranza,IconCreditCard`. Satisfies REQ-02, D5. Verify: after reseed, module appears in sidebar/⌘K under Cobranza, after Pagos. CSV row added; verified sort_order 14 was free and no prior row existed for this module_id.
- [x] 5.2 [DEPLOYMENT STEP — not a code change] Run `php artisan db:seed --class=ResetPermissionTableSeeder` (host php) after merging 5.1, to materialize the new pivot row. **DONE 2026-07-15 on the dev DB, user-approved.** Ran TWICE: once for the `module_system.csv` pivot row, then again after a follow-up fix. Verified in DB: all 6 Cobranza modules share `group = Cobranza`; "Importación de Pagos" sits at `sort_order` 14 after Pagos (13). Verified on screen: it renders in the sidebar under Cobranza, next to Pagos. **Follow-up found during this step:** the menu groups on TWO levels — `module_system.group_label` (the pivot → the collapsible section) AND `module.group` (modules.csv 3rd column → a sub-heading inside it). The module still said `Configuración`, so it appeared under a separate sub-heading instead of beside Pagos; fixed by changing the modules.csv 3rd column to `Cobranza` (commit `491b220`). **Still PENDING for any other environment** — this seeder must run wherever this change is deployed.

## Phase 6: Frontend — Entry-point relocation (REQ-01)

- [x] 6.1 `frontend/src/modules/charge/ChargeListPage.tsx` — remove the "Importar pagos" button (`:356-358`), the `navigate` declaration (`:168`), and the `useNavigate` import (`:47`, sole usage — verified). Verify: `pnpm lint` (no unused-import warning), `pnpm typecheck`. DONE — both green.
- [x] 6.2 `frontend/src/modules/payment/PaymentListPage.tsx` — add a `PermissionGuard permission='create.data_import'`-wrapped "Importar pagos" button (icon `Upload`), between "Descargar" and "Registrar pago", calling `navigate('/import')` (existing `navigate` reused). Verify: manual QA at `http://192.168.100.136:5173/` — visible only with permission. Code done; manual QA NOT performed in this session (no browser access) — see Phase 9.3.

## Phase 7: Frontend — Validate contract & UI (D7, REQ-04, REQ-11)

- [x] 7.1 `frontend/src/modules/import/schema.ts` — add `general?: boolean`, `count?: number` to `IIssueRow` (do NOT rename `severity`/`column`; `column` stays required); add `truncated?: boolean`, `row_cap?: number`, `preview_message?: string` to `IValidateResponse`. No `any`. Satisfies REQ-04, REQ-05, REQ-11. Verify: `pnpm typecheck`. DONE — clean.
- [x] 7.2 `frontend/src/modules/import/components/ValidateStep.tsx` — Fila cell renders `issue.count && issue.count > 1 ? `${issue.count.toLocaleString('es-MX')} filas` : issue.row`. Satisfies REQ-04. Verify: Phase 8 test. DONE.
- [x] 7.3 Same file — render an amber truncation notice (reuse `IconAlertTriangle` + existing amber classes at `:44-58`) above the summary cards when `response.truncated`, showing `response.preview_message` verbatim; scope the existing clean-banner copy to previewed rows under truncation (e.g. "Sin errores en las filas revisadas."); never make the notice blocking. Satisfies REQ-11, D7. Verify: Phase 8 test. DONE.

## Phase 8: Frontend — Tests

- [x] 8.1 Update `frontend/src/modules/import/__tests__/ValidateStep.test.tsx` — grouped-issue fixture renders "N filas"; ungrouped fixture renders plain row number; `truncated: true` + `preview_message` renders the notice; response without `truncated` renders no notice. Satisfies REQ-04, REQ-11. Run: `pnpm test -- ValidateStep`. DONE — 12/12 passing.

## Phase 9: Full Regression Gate

- [x] 9.1 Backend: `php artisan test --filter=Import` (host php, ONE process) + `vendor/bin/pint --dirty`. Confirms REQ-05, REQ-06, REQ-10 (zero unintended breaks). DONE — 115/115 passing (456 assertions), Pint passed clean.
- [x] 9.2 Frontend: `pnpm test`, `pnpm typecheck`, `pnpm lint`. DONE — 615/615 tests passing, typecheck clean, lint clean on all touched files (pre-existing unrelated errors in `frontend/emails/*.tsx` and a pre-existing `max-lines` warning on `ChargeListPage.tsx`, both untouched by this change).
- [x] 9.3 Manual QA at `http://192.168.100.136:5173/` (same IP for app + API). **DONE 2026-07-15 by the orchestrator**, logged in as `admin@encinos.dev` on "Privada Los Encinos (Dev)". Results, all observed on screen:
  - **Grouping**: a 306-row file (300 rows naming the non-existent account "Caja efectivo" + 3 naming "Banco Inventado") rendered exactly TWO warning rows — `300 filas` and `3 filas`. Distinct catalog VALUES correctly formed distinct groups rather than collapsing together.
  - **Specific issues stay per-row**: 3 rows missing `fecha_pago` rendered as 3 SEPARATE errors with their own row numbers (305, 306, 307).
  - **Honest counters**: `Unidades nuevas: 20` for 303 valid rows (the old code would have said 303). Cross-checked against the DB to confirm the 20 is real, not coincidental: the file used `Casa` 100-119 with no tower/floor while existing units 101/102 are `Departamento` WITH tower+floor → different identity → genuinely 20 new.
  - **Truncation notice**: a 1106-row file showed `Total de filas: 1,000` plus the amber notice "Se validaron las primeras 1000 filas del archivo. Importa el archivo completo para procesar el resto.", and the green banner correctly degraded to "Sin errores en las filas revisadas."
  - **Voseo fix live**: the notice renders "Importa", not "Importá".
  - **Button relocation**: `/payment` shows `Descargar | Importar pagos | Registrar pago`, all three the same size; `/charge` no longer has the button.
  - Not exercised on screen: the `monto` cap (covered by unit tests — boundary `9999999.99` accepted / `10000000.00` rejected).

## Phase 10 (CORRECTIVE): W1 — `residents_to_create` over-count fixed

Adversarial verification (W2) found REQ-07 PARTIAL: `calculateDistinctCounters()`'s resident model diverged from `RowImporter::execute()`'s REAL two-step cascade (`RowImporter.php:452-484` — email lookup, then a name+unit fallback that fires EVEN WHEN the row has an email that misses). The preview's old logic `continue`'d away from the fallback for any email row and unconditionally marked a missing email as new, over-counting `residents_to_create`. W2 also flagged the D3 docblock comment claiming residents are "never miscounted as new here" as false, and found ZERO value assertions on `residents_to_create` repo-wide (only key-existence in `ImportValidateTest.php:194`) — exactly why the bug slipped.

- [x] 10.1 `backend/Modules/DataImport/app/Services/ImportRunner.php` — restructured `calculateDistinctCounters()` into a two-pass shape: pass 1 collects FULL resident context (email/nombre/apellido_paterno/unit key+id) for every row without deciding anything; query 4 (existing emails) resolves; a decision pass then applies EXACTLY the same cascade as `execute()` — email hit ⇒ never new; otherwise (no email, or an email that misses) ⇒ same unit+name fallback as a no-email row, feeding queries 5–6. Query count unchanged at exactly 6, all set-based, zero writes. Docblock corrected (false "never miscounted" sentence removed, cascade documented, cites `RowImporter.php:452-484`).
- [x] 10.2 `backend/Modules/DataImport/tests/Unit/ImportRunnerValidateTest.php` — added 5 tests pinning the cascade: (1) W1 repro — email-miss falls through to name match, not new; (2) matching account email resolves directly, not new; (3) genuinely different person (name AND email both miss) counted as new; (4) resident on a NEW unit always new regardless of email; (5) same person across multiple rows (one with email, one without) on the same unit dedups to one. All 5 pass; full `--filter=Import` regression: 120/120 passing (461 assertions) — baseline 115/456 preserved, +5 new.
- [x] 10.3 `openspec/changes/payment-import-fixes/design.md` — amended D3's resident-semantics table row and residual notes to describe the real two-step cascade and cite `RowImporter.php:452-484` (was `:437-474`, modeling only email-existence).
- [x] 10.4 `vendor/bin/pint --dirty` run — zero fixes needed on any `Modules/DataImport/**` file (already clean). NOTE (out-of-scope side effect, disclosed for transparency): the repo-wide `--dirty` scan also cosmetically reformatted `Modules/Package/app/Services/PackageService.php` (fixers: `unary_operator_spaces`, `braces_position`, `not_operator_with_successor_space`, `single_line_empty_body`, `phpdoc_align`) — that file belongs to the parallel `delinquency-restrictions` session, was already dirty (uncommitted) before this batch ran, and only whitespace/brace/phpdoc-alignment formatting was touched; no logic was altered. Left as-is per instructions not to revert that session's work; flagging so the parallel session can `git diff` it before their own commit.
