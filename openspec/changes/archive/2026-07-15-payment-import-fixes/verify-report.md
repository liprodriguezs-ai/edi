# Verification Report — payment-import-fixes

- **Change**: `payment-import-fixes`
- **Mode**: Standard (strict_tdd: false — not evaluated for test-first ordering)
- **Store**: hybrid (this file + Engram `sdd/payment-import-fixes/verify-report`)
- **Date**: 2026-07-14
- **Verdict**: **PASS WITH WARNINGS** — 0 CRITICAL, 3 WARNING, 3 SUGGESTION

---

## Completeness

| Phase | Tasks | State |
|---|---|---|
| 1–4 Backend (marking, collapse, counters, rules, texts, tests) | 10/10 | Complete — independently corroborated |
| 5 Menu registration | 1/2 | 5.1 done; 5.2 pending-deployment (accepted) |
| 6–8 Frontend (relocation, contract, UI, tests) | 6/6 | Complete — independently corroborated |
| 9 Regression gate | 2/3 | 9.3 manual QA pending (accepted) |

Every ticked task was checked against code state; no task claims completion the code does not support. The two unticked items are honestly marked and out of an apply agent's reach.

## Scope Integrity

`git diff --stat HEAD` on the declared surface returns **exactly** the declared files and nothing else:

- Backend: `ImportRunner.php`, `ReferenceResolver.php`, `RowImporter.php`, `TemplateGenerator.php`, `ReferenceResolverTest.php`, `RowImporterValidateTest.php`, `module_system.csv`, + untracked `ImportRunnerValidateTest.php`.
- Frontend: `ChargeListPage.tsx`, `PaymentListPage.tsx`, `schema.ts`, `ValidateStep.tsx`, `ValidateStep.test.tsx`.

**No out-of-scope file touched**: `PaymentService`, FIFO, earmark, `/import/execute`, chunking, `ImportController`, and `WorkbookReader` are all unmodified. REQ-10 holds structurally.

## Runtime Evidence

| Command | Result |
|---|---|
| `php artisan test --filter=Import` (host php, single process) | **115 passed** (456 assertions), exit 0 — run by orchestrator; not re-run (cost) |
| `pnpm vitest run src/modules/import/__tests__/ValidateStep.test.tsx` | **12/12 passed**, exit 0 — run by this agent |
| `pnpm typecheck` (`tsc -p tsconfig.app.json --noEmit`) | exit **0**, clean |
| `pnpm lint` | 0 errors on touched files; only finding is pre-existing `max-lines` warning on `ChargeListPage.tsx:532` (705 > 500) — this change *shrank* the file by 5 lines |
| `pnpm vitest run` (full) | 629/630 passed. **1 failure attributed to a parallel session, NOT this change** (see below) |

**Full-suite failure attribution**: `src/modules/access/components/__tests__/VisitorFormModal.test.tsx > VisitorFormModal — delinquency notice` times out at 5000ms. `VisitorFormModal.tsx` is *modified-uncommitted* and its `__tests__/` dir is *untracked* — both belong to the in-flight `delinquency-restrictions` session. `rg` confirms the file has **zero dependency** on this change's surface. The apply report's "615/615" was accurate at apply time; the suite has since grown to 630 as that session added tests. Not evaluated further, per scope.

---

## Spec Compliance Matrix

| Req | Verdict | Evidence |
|---|---|---|
| REQ-01 Entry point on Pagos, not Cargos | **PASS** | `PaymentListPage.tsx:212-217` — button wrapped in `<PermissionGuard permission='create.data_import'>`, positioned between Descargar (`:197-211`) and Registrar pago (`:218-223`). `ChargeListPage.tsx` diff removes button, `navigate` decl, and `useNavigate` import; `rg` finds **zero** residual hits. `/import` route still gated: `App.tsx:738-740` `<PermissionRoute permissionEntity='data_import' />` (untouched). Bonus: the removed Cargos button was **ungated**; the replacement is guarded. |
| REQ-02 Module in sidebar + ⌘K under Cobranza | **PASS** (materialization pending seeder) | `module_system.csv:96` = `a1301001-…,b266838b-…,14,Cobranza,IconCreditCard`, byte-matching D5. Header verified; sort 14 free (Cobranza siblings: 10,10,11,12,13 with Pagos=`c4001004`@13) → lands immediately after Pagos. `MenuController.php:120` groups by `pivot->group_label` (→ "Cobranza"); `command-palette.tsx:14-23` labels entries from `section.label`, the same pivot value. No hardcoded bypass. |
| REQ-03 `general` marked structurally | **PASS** | `ReferenceResolver::buildIssue()` `:315-324` returns `'general' => true`; all 7 resolvers route through it. The mandatory-field errors are built inline at `RowImporter.php:86-91` with **no** `general` key — structurally impossible to mark. Email cross-tenant warning uses `RowImporter::buildIssue()` `:708-716`, also without the key. Detection is `empty($warning['general'])` (`ImportRunner.php:130`) — structural, never text. Tests assert `general === true` on all 7 (`ReferenceResolverTest.php:66,137,154,169,184,226,257`). |
| REQ-04 General collapse w/ count; per-row flat | **PASS** | `ImportRunner::collapseGeneralIssues()` `:111-150`, called from response assembly `:89` (not `RowImporter`). Errors pass first + untouched `:117-121`; non-general warnings pass through `:130-133`; general group on `column|message` `:136`. Index recorded **before** push (`:139` `= count($collapsedWarnings)` then `:141` `[]=`) → increment `:146` targets the right slot; array only ever appended so indices stay contiguous. **Corroborated: no bug.** FE `ValidateStep.tsx:159-161`. All 3 scenarios have passing tests. |
| REQ-05 Validate response contract preserved | **PASS** | All 6 top-level keys intact `:83-90`; `truncated`/`row_cap`/`preview_message` still added by `ImportController.php:102-109` *before* the blocked branch, and survive `array_merge` `:122`. The 422 loop `:111-125` filters `severity === 'error'` — errors are never collapsed, so `rows.<n>.<col>` keys hold. Test `test_error_issues_stay_flat_and_keyable_as_rows_n_column` asserts `/^rows\.\d+\.[a-z_]+$/`. |
| REQ-06 RowImporter per-row contract unchanged | **PASS** | `validate()` return shape unchanged; `general?` is additive via pass-through. Docblock `:46` updated. No key removed/renamed. Grouping + counters live entirely in `ImportRunner`. The `monto` rule/message changes alter *behavior* (mandated by REQ-08), not the return contract. |
| REQ-07 Counters report distinct new entities | **PARTIAL** | `units_to_create` is **exact** and mirrors execute (see identity table below); `payments_to_register = $validCount` computed explicitly `:87`. Tests: `units_to_create === 3` for 1000 rows/50 units ✓, drift guard ✓. **`residents_to_create` diverges from execute in one branch and has zero value assertions** — see W1/W2. |
| REQ-08 Payment amount cap | **PASS** | `RowImporter.php:64` `'max:'.(MoneyLimit::MAX_MINOR_UNITS / 100)`. `MoneyLimit.php:11` = `999999999` (centavos) → **`max:9999999.99` in PESOS**. This is the correct denomination — **not** the 100× bypass. Mandatory inline comment present `:59-63`. Message `:81` literal. Per-row error (no `general`) → never collapsed. Boundary tests: `9999999.99` accepted, `10000000.00` rejected with the Spanish message. |
| REQ-09 Texts truthful + Spanish | **PASS** | `ReferenceResolver.php:103-104` now reads "se usará el estado por defecto del clúster" — **no "sin estado"**; asserted both ways (`ReferenceResolverTest.php:138-139`). Verified against reality: `unit_status_id` is NOT NULL and `RowImporter.php:366-392` always resolves a status. LÉEME `TemplateGenerator.php:68` states the **three behaviors distinctly, ungrouped**: auto-create+aviso / torre-sección "se importa sin ese dato" / estado "usa el estado por defecto … se crea 'Activa'". 3 `.string` Spanish messages `:68,:72,:75`, each asserted. Sibling texts re-checked for truth: `:137` "sin torre" and `:170` "sin sección" are accurate (execute leaves the FKs NULL). |
| REQ-10 Domain contracts unchanged | **PASS** | Diff proves zero writes to `PaymentService`/FIFO/earmark/`/import/execute`/chunking. `validate()`'s zero-write guarantee intact — all 6 counter queries are reads; `ImportValidateTest`'s zero-write assertions stayed green in the 115-test run. No migration; only the `module_system.csv` row. |
| REQ-11 Truncated preview disclosed | **PASS** | **FE-only** — `ImportController` untouched. `schema.ts:36-41` adds the 3 optional fields. `ValidateStep.tsx:43-48` renders on `response.truncated`, printing `{response.preview_message}` **verbatim** (no client-side re-composition). Non-blocking: `disabled={hasBlocked}` `:89` unaffected. Clean banner scoped `:65-67` → "Sin errores en las filas revisadas." Both scenarios tested and passing. |

### REQ-07 identity semantics — line-by-line mirror check

| Component | `ImportRunner` | `RowImporter` (execute) | Match |
|---|---|---|---|
| type | `:208-209` `mb_strtolower(trim(...))` → map, else `'new:'.$typeName` | `:316-329` resolver `LOWER(name)=?` on trimmed input; unregistered → auto-create | OK |
| tower | `:211-212` blank **or** unregistered → `''` | `:336-337,:348-354` both → `whereNull('tower_id')`; create omits key | OK |
| floor | `:215` `isset && !== '' ? (string) : ''` — no trim | `:342` `isset && !== '' ? (string) : null` — no trim | OK |
| number | `:216` `(string)($identity['numero'] ?? '')` | `:343` `(string)($row['numero'] ?? '')` | OK |
| DB key | `buildUnitDbKey()` `:319-322` | unique index COALESCE semantics; `:363` `whereNull('deleted_at')` = SoftDeletingScope | OK |
| resident (email) | `:225` `!empty ? mb_strtolower(trim(...)) : null` | `:447` byte-identical | OK |
| resident (name) | `:226-227` `trim(...)` | `:448-449` byte-identical | OK |
| resident (name fallback when email misses) | **absent** — `:229-233` `continue`s on email presence | `:470-484` applies `(unit+nombre+apellido)` fallback **unconditionally** when the email lookup misses | **MISMATCH — W1** |

**`new:{name}` sentinel — confirmed correct.** `:209` keys per **DISTINCT lowercased type name**, so `Depa/101` and `Loft/101` build `new:depa|||101` and `new:loft|||101` = **2 distinct units, not 1**. A `new:`-prefixed key can never collide with a UUID-prefixed DB key, so it is always counted new — exactly what execute does (auto-creates the type, then finds no unit). Repeat rows for the same new unit dedupe via the array key.

**No N+1 — corroborated.** Exactly 6 queries (`:178`, `:182`, `:188`, `:255`, `:273`, `:284`), 3 conditional, all outside loops. The `foreach` at `:207-248` issues zero queries; `:278-280` zero; `:291-306` resolves via the in-memory `$linkedUsersById->get()`. Confirmed independently.

---

## Design Coherence

| Decision | State |
|---|---|
| D1 `general` in `ReferenceResolver::buildIssue()`, `RowImporter::buildIssue()` untouched | OK — as designed |
| D2 collapse in `ImportRunner`, key `column\|message`, count starts at 1, stable order | OK — semantics exact. Minor: implemented as two passes over the data rather than D2's single-pass pseudocode — same O(n), byte-identical output. Non-issue. |
| D3 6-query ID-based diff, no relation calls, `$blockedRows` removed | Structure as designed. D3's mandated *"Documented residuals in PHPDoc"* were **not written**, and the docblock asserts the opposite (W1). |
| D4 pesos cap `max:9999999.99` + inline comment | OK — as designed |
| D5 CSV row | OK — byte-match |
| D6/D6.b texts | OK — as designed |
| D7 FE-only truncation notice, verbatim `preview_message`, scoped clean banner | OK — as designed |
| Design said `ReferenceResolverTest` in `tests/Feature/` | Code has it in `tests/Unit/` — apply correctly trusted code over artifact. Non-issue. |

---

## Issues

### CRITICAL — none

### WARNING

**W1 — `residents_to_create` over-counts when a new email lands on an already-linked resident.**
`ImportRunner.php:229-233` short-circuits on email presence (`continue`), so the row never reaches the name-based diff; `:261-265` then counts every unseen email as a new resident. But execute (`RowImporter.php:454-484`) runs the email lookup **and, when it misses, still applies the `(unit_id + nombre + apellido_paterno)` fallback** — finding the linked person and setting `residentCreated = false`.

*Reproduction*: unit "Casa 101" exists with resident "Juan Pérez" (no email). Import row: `Casa/101, nombre=Juan, apellido_paterno=Pérez, email=juan@nuevo.com`. Execute creates **0** residents; preview reports **1**.

The same gap produces the residual D3 itself acknowledged ("person appearing with-and-without email counts twice"). `ImportRunner.php:164-166` currently claims the opposite: *"Key semantics mirror RowImporter's execute-time resolution exactly … so a row that will resolve to an existing DB row at execute() time is never miscounted as new here."* **That sentence is false for this path** and should not ship as-is.

*Assessment*: traces to **D3's approved resident model** (which cites `:437-474` but models only the email-existence diff), not to an apply defect — apply implemented the design faithfully. Preview-accuracy only: it **over-counts, never under-counts**, and no execute behavior, write path, or data integrity is affected. The headline defect REQ-07 exists to fix (valid-row count, e.g. 1000 instead of 50) **is** fixed, and `units_to_create` — the spec's only counter scenario — is exact. Fixing it requires a design amendment, not a re-apply of existing tasks.

**W2 — `residents_to_create` has zero value assertions; its existence-diff branch never executes.**
`rg residents_to_create` across all backend tests returns exactly one test-file hit: `ImportValidateTest.php:194`, inside `assertJsonStructure()` — key existence only, never the value. No validate-path test creates a `UnitUser` link (`ImportValidateTest` only calls `UnitUser::count()` at `:222/:239` for the zero-write assertion), so `ImportRunner.php:284-289` (query 6) always takes the `: collect()` fallback and the *"already-linked resident → not new"* decision at `:291-306` is **never exercised**. The 6 queries all run without SQL errors (validating the `whereIn(DB::raw('LOWER(email)'), …)` construct), but the most branch-dense ~60 lines of new code are unasserted. This is why W1 went unnoticed.

**W3 — Newly user-visible Argentine voseo in `preview_message`.**
`ImportController.php:108`: *"Se validaron las primeras {$rowCap} filas del archivo. **Importá** el archivo completo para procesar el resto."* — voseo, not Mexican Spanish (`Importa`). The string is pre-existing and outside the declared surface, but D7 established it had **zero FE readers**; this change is precisely what promotes it from dead text to admin-visible copy. The spec's REQ-11 scenario quotes it verbatim including "Importá", so rendering it is spec-compliant — REQ-11 and REQ-09 both pass. It violates the project's Mexican-Spanish convention in newly-surfaced user-facing text. One-word fix at `ImportController.php:108`.

### SUGGESTION

**S1** — `PaymentListPage.tsx:213` uses `size='sm'` while its adjacent "Descargar" sibling (`:198-210`) is default size, so the middle outline button renders shorter than both neighbors. D7's snippet specified no `size` + `className='flex items-center gap-2'`. Cosmetic; worth an eyeball during the pending manual QA.

**S2** — Hairline identity divergence for a pathological `torre` value of `'0'`: `ImportRunner.php:211` uses `! empty($identity['torre'])` (PHP treats `'0'` as empty, yielding `''`), while execute passes `$row['torre'] ?? null` straight to `resolveTower()` (`ReferenceResolver.php:117` rejects only `null`/`''`), which would resolve a tower literally named "0". Practically unreachable, and it matches the pre-existing `! empty()` convention at `RowImporter.php:112`.

**S3** — Pre-existing dead code noticed in a touched file: `ReferenceResolver::$pendingIssues` (`:30-37`) is assigned once at `:55` and never read anywhere.

### Spec-wording note (not a defect)

REQ-03's third invariant reads *"No grouping logic MAY rely on string comparison of message text"*, while D2 explicitly specifies the dedup key `column|message` (`ImportRunner.php:136`). The implementation follows D2. Read with REQ-03's title and requirement sentence (*"marked structurally, not detected by text"* / *"never inferred from message text"*), the invariant clearly governs **generality detection** — which is purely structural at `:130` — not the dedup key of an already-marked set. Scored PASS; the wording could be tightened to "may not *infer generality* from message text".

---

## Verdict

**PASS WITH WARNINGS.** 10/11 requirements PASS, REQ-07 PARTIAL. Zero CRITICAL — nothing blocks archive.

The change is sound. The three named traps were all avoided: the cap is `max:9999999.99` in pesos (not the 100× centavos bypass), the `general` mark is structural with the mandatory errors never marked, and the `new:{name}` sentinel keys per distinct type name. `collapseGeneralIssues()` and the 6-query non-N+1 counter both corroborate clean on independent inspection.

The one real defect is W1: `residents_to_create` over-counts in a branch the approved design did not model, and W2 explains why no test caught it. Both are preview-accuracy issues with no execute-path or data-integrity impact, and both trace to the design rather than to apply. W3 is a one-word dialect fix in newly-visible copy.

**Recommended**: proceed to `sdd-archive`, with W1/W2/W3 either folded into a small follow-up or fixed inline before merge (W3 is a one-word edit; W1+W2 need a design amendment to the resident model plus a covering test that creates a `UnitUser` link). Manual QA (task 9.3) and the `ResetPermissionTableSeeder` deployment step (task 5.2) remain correctly open.
