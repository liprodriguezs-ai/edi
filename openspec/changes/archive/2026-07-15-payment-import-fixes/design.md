# Design: Payment Import Fixes

- **Change**: `payment-import-fixes` | Store: hybrid (this file + Engram `sdd/payment-import-fixes/design`)
- Inputs: proposal (`sdd/payment-import-fixes/proposal`), exploration (#1552), user-approved plan (wins on divergence).

## Technical Approach

Six surgical fixes, no schema changes. Grouping and counters are assembled in `ImportRunner::validate()` (response assembly), never in `RowImporter` — all per-row contracts, the 422 loop (`ImportController.php:111-125`), and `validate()`'s documented ZERO-write guarantee stay intact. New response fields are optional/additive.

## Architecture Decisions

### D1 — The `general` mark (structural, never text-dedup)

**Choice**: hardcode `'general' => true` inside `ReferenceResolver::buildIssue()` (`ReferenceResolver.php:309`) — no new parameter. All 7 call sites flow through unchanged. `RowImporter::buildIssue()` (`:698`) stays **untouched**: its only validate-side caller is the per-row email warning (specific by design), so its `@return` shape is already exact.
**Docblocks updated**: `ReferenceResolver::buildIssue` → `array{row: int, severity: string, column: string, message: string, general: bool}`; merged-stream shapes gain optional keys: `RowImporter::validate()` `@return` (`:45`) adds `general?: bool`; `ImportRunner::validate()` `@return` (`:34-41`) adds `general?: bool, count?: int`.
**Alternatives rejected**: `bool $general = false` param on both builders (dead generality — every resolver issue is memoized per catalog VALUE, hence row-independent = general by construction); message-text matching (FORBIDDEN — the 12 mandatory errors are byte-identical across rows); reusing `ProcessImportChunkJob::isAutoCreateWarning()` (matches preterite "se creó"; validate emits future "se creará" — zero overlap).
**Verified side effect**: execute-path resolver issues also gain the key — safe: no DataImport test asserts exact issue shapes (grep-verified), report JSON and FE fields are additive.

### D2 — Collapse algorithm in `ImportRunner::validate()`

**Choice**: keep the loop's `array_push` (`:59`); replace `'rows' => $allIssues` (`:78`) with `'rows' => $this->collapseGeneralIssues($allIssues)`. Single pass, O(n):

```php
foreach ($allIssues as $issue) {
    if ($issue['severity'] === 'error') { $errors[] = $issue; continue; }
    if (($issue['general'] ?? false) !== true) { $warnings[] = $issue; continue; }
    $key = $issue['column'].'|'.$issue['message'];
    isset($groupIndex[$key])
        ? $warnings[$groupIndex[$key]]['count']++
        : $groupIndex[$key] = array_push($warnings, $issue + ['count' => 1]) - 1;
}
return [...$errors, ...$warnings];
```

- **Dedup key**: `column|message` — message embeds the offending value, so distinct values form distinct groups (two unknown towers → two groups). Never applied to non-`general` issues.
- **Ordering**: stable — all errors first (row order), then warnings in first-encounter row order (each group anchored at its first occurrence).
- **`row` on a group** = first affected row; `count`/`general` present only on grouped entries — errors stay byte-identical to today. No affected-row samples (YAGNI; additive later).

### D3 — Honest distinct counters (orchestrator-corrected: validate resolves NOTHING today)

| Option | Tradeoff | Decision |
|---|---|---|
| (A) Batched existence diff | 6 constant, bounded, read-only queries; exact vs execute | **CHOSEN — units AND residents** |
| (B) Distinct-in-file + relabel cards | 0 queries, but answers a weaker question and forces UI relabel | Rejected |
| (C) Keep `$validCount` | The lie being fixed | Rejected |

**Rationale**: unit identity is authoritatively defined by the unique expression index `unit_identity_unique` (`Housing/.../2026_05_31_100003_create_unit_table.php:32-36`): `(tenant_id, unit_type_id, COALESCE(tower_id::text,''), COALESCE(floor,''), COALESCE(number,''))` on live rows. A cluster has hundreds of units → ONE bounded fetch beats per-row lookups (infra-cost rule); primary use case (payments for existing units) demands DB-awareness, or "Unidades nuevas: 50" stays a lie.

**Mechanics** (private methods in `ImportRunner`; `PreviewCounter` service rejected as premature): collect identity tuples during the existing loop for error-free rows; finalize with exactly **6 constant reads** — the diff compares on **IDs**, not names (validator suggestion adopted: drops the two relation eager loads AND removes any dependency on `Unit` relation method names):
1. `UnitType::get(['id','name'])` → lowercased-name→id map (TenantScope)
2. `Tower::get(['id','name'])` → lowercased-name→id map (TenantScope)
3. `Unit::get(['id','unit_type_id','tower_id','floor','number'])` → DB key set `{unit_type_id}|{tower_id ?? ''}|{floor ?? ''}|{number ?? ''}` + key→unit_id map (mirrors the index COALESCEs; no `with()` needed)
4. `User::withoutGlobalScope(UserTenantScope::class)->whereIn(DB::raw('LOWER(email)'), $emails)` → existing-email set
5. `UnitUser` links for matched existing unit ids (explicit `tenant_id` where, mirroring `RowImporter.php:462-465`)
6. Linked users' `name`/`last_name` for those links

Zero writes → `ImportValidateTest` zero-write assertions stay green. (For accuracy: `Unit` relations are `unitType()` at `Unit.php:89` and `tower()` at `:99` — camelCase, deviating from the snake_case convention — but the ID-based diff reads FK columns directly, so no relation call exists to get wrong.)

**Key semantics (mirror execute EXACTLY)**:

| Component | Rule | Mirrors |
|---|---|---|
| type | `mb_strtolower(trim(tipo_unidad))` → mapped `unit_type_id`; unregistered → component `new:{lowercased name}` — keyed per DISTINCT type name, so `Depa/101` and `Loft/101` remain two distinct new units; a `new:`-prefixed key can never match a DB key ⇒ always NEW (execute auto-creates) | `RowImporter.php:306-319` |
| tower | `mb_strtolower(trim(torre))` → mapped `tower_id`; blank OR unregistered → `''` (execute resolves both to NULL tower_id, no auto-create) | `:326`, index COALESCE |
| floor | `$row['piso'] !== '' ? (string)$row['piso'] : ''` — **no trim** | `:332` |
| number | `(string)($row['numero'] ?? '')` — **no trim** | `:333` |
| resident | **TWO-STEP CASCADE, mirroring `resolveOrCreateResident()` EXACTLY**: (1) email lookup — `mb_strtolower(trim(email))` (emails stored verbatim → LOWER compare) against existing accounts; a hit is never new, regardless of name. (2) name fallback — fires whenever step 1 does NOT resolve a user, i.e. EITHER the row has no email AT ALL, OR it has an email that does not match any existing account. The fallback is `unitKey\|nombre\|apellido_paterno` **exact case** against `unit_user`-linked residents on that unit; on a NEW unit → always new (no link can exist yet); on an existing unit → diff vs linked users' names. **W1 corrective note**: an earlier revision of this design/implementation only modeled email-vs-DB existence and `continue`'d away from the name fallback whenever a row had an email, which over-counted `residents_to_create` for exactly the case in step 2 above (email present but missing, existing unit, name actually matches a linked resident). Fixed in the corrective batch — see apply-progress. | `RowImporter.php:452-484` |

`payments_to_register` stays `$validCount` (semantics: rows that will attempt registration; folio-skip actuals belong to the execute report's `payments_skipped`). `$blockedRows` (`:46,:55`) dead code → **removed**. Documented residuals in PHPDoc: person appearing with-and-without email, where the email ALSO matches an existing account, resolves via step 1 and is never new (no residual); person appearing with-and-without email where the email misses falls through to the SAME name-fallback key for both rows and dedups to one (residual only if the two rows disagree on name capitalization — exact-case match, same as execute); cross-tenant email → invitation, correctly not-new; under `truncated` (cap 1000, `config.php:13`) counters describe only previewed rows — the existing `preview_message` (`ImportController.php:102-109`) already frames this; a drift-guard feature test pins "existing unit not counted".

### D4 — Money cap: PESOS, not centavos (100× trap)

**Choice**: `'monto' => ['required', 'numeric', 'gt:0', 'max:'.(MoneyLimit::MAX_MINOR_UNITS / 100)]` — evaluates to `max:9999999.99`. Import `Modules\Collections\Support\MoneyLimit`.
**Rejected**: copying the API rule's `max:'.MoneyLimit::MAX_MINOR_UNITS` verbatim — `monto` is in PESOS and only becomes centavos AFTER validation (`RowImporter.php:176` `* 100`), so `max:999999999` on the peso field would ACCEPT up to $999,999,999 (~$1B) — a 100× cap **bypass** (too permissive), not a tighter limit. The constant is centavos-denominated; expressing the same cap in the sheet's peso denomination requires `/ 100`.
**Float boundary** (execution-verified): `999999999 / 100` is PHP float division → `float(9999999.99)`, no intdiv hazard; it serializes exactly as `9999999.99` at default precision=14, so the rule string is `max:9999999.99`. Validator and parser share the same double: any accepted x has `x*100 ≤ 999999999.0000000224` → `(int) round()` ≤ `MAX_MINOR_UNITS`; boundary `9999999.99` accepted (lands exactly on MAX), `9999999.995` rejected. Mandatory inline comment explaining the pesos/centavos division.
**Message**: `'monto.max' => 'El monto no puede exceder $9,999,999.99.'` (literal — `:max` would render unformatted `9999999.99`).

### D5 — Menu seeding

Append to `backend/database/seeders/csv/module_system.csv` (header `module_id,system_id,sort_order,group_label,group_icon` verified; no existing row for the module — verified):
`a1301001-0000-4000-8000-000000000001,b266838b-4cb0-4eb1-aa76-242cf96df41a,14,Cobranza,IconCreditCard`
System `b266838b` = Fraccionamiento; sort 14 free (Pagos=13, next 20). `IconCreditCard` = Cobranza GROUP icon (matches siblings); module icon `IconFileImport` already in `seedModuleIcons()`. **Deployment step**: `php artisan db:seed --class=ResetPermissionTableSeeder` (host php).

### D6 — Texts

- `TemplateGenerator.php:68` LÉEME row 1, second cell → truthful (Mexican Spanish, tú), covering the THREE distinct behaviors: (a) tipo de unidad + cuenta destino auto-create with warning (`RowImporter.php:312`, `:682`); (b) torre/sección resolve to NULL — unit imported without that field (`:326-330`); (c) estado de unidad falls back to the tenant's default status, and `'Activa'` is created only when the tenant has none (`:361-381`):
  `'Recomendamos registrar antes los catálogos (tipos y estados de unidad, torres, métodos de pago, cuentas). Al importar: un tipo de unidad o una cuenta destino que no existan se crean automáticamente (con aviso); una torre o sección no registrada NO se crea — la unidad se importa sin ese dato; un estado de unidad no registrado usa el estado por defecto del clúster (y si no tienes ninguno, se crea "Activa").'`
- Add to messages array (`RowImporter.php:59-72`): `'tipo_unidad.string' => 'El tipo de unidad debe ser texto.'`, `'nombre.string' => 'El nombre del residente debe ser texto.'`, `'apellido_paterno.string' => 'El apellido paterno debe ser texto.'`
- **(D6.b — ADDED by the orchestrator; same defect class, scope confirmed)** `ReferenceResolver.php:103-104` — the `estado_unidad` warning is FALSE and must be corrected in the same pass:

  Current: `"El estado de unidad '{$name}' no está registrado — la unidad se importará sin estado."`

  **"Sin estado" is impossible.** `unit_status_id` is NOT NULL (`create_unit_table.php:24` — `foreignUuid('unit_status_id')->constrained('unit_status')`, no `->nullable()`), and `RowImporter.php:361-381` falls back to the tenant's first `unit_status` (ordered by `order`, `id`) and only creates `'Activa'` when the tenant has none. The unit ALWAYS ends up with a status.

  Replacement (Mexican Spanish, tú): `"El estado de unidad '{$name}' no está registrado — se usará el estado por defecto del clúster."`

  **Why in scope**: this is the identical defect REQ-09 exists to prevent (text contradicting actual auto-create/fallback behavior), it lives in a file D1 already modifies, and it is one of the 7 GENERAL warnings D2 collapses — so after grouping it becomes a single prominent line the admin actually reads. Leaving it would ship a fix that makes a lie *more* legible. Surfaced by the design agent's own out-of-scope note during the corrective re-run; the orchestrator verified the claim against `create_unit_table.php:24` and `RowImporter.php:361-381` before accepting it.

  **Bounded**: text only. Do NOT change the fallback logic, and do NOT touch the sibling execute-side message at `RowImporter.php:379-380` (`"No existe ningún estado de unidad — se creó 'Activa' por defecto."`) — that one is already accurate.

### D7 — Truncation notice (ADDED after design; user-approved scope addition)

**Problem**: `ImportController.php:102-109` already returns `truncated`, `row_cap`, and `preview_message` when the file exceeds `validate_row_cap` (1000), and its comment asserts *"The admin isn't misled"*. **That assertion is false**: `rg` over `frontend/src/` finds ZERO readers of those keys (the only `truncated` hits are `Math.trunc` in `currencyHelper.ts`). A 5000-row file silently validates its first 1000 and reports "Sin errores. El archivo está listo para importar." — the user's own screenshot ends at row 1001, exactly the cap boundary (`WorkbookReader.php:148`: `$rowNumber = $index + 2`, so 1000 data rows = rows 2..1001).

**Choice**: **Frontend-only.** The backend contract is already correct and complete — do NOT touch `ImportController` or `WorkbookReader`. Render the backend's own `preview_message` verbatim (never re-compose the sentence client-side; the backend owns the copy).

- `schema.ts` → `IValidateResponse` gains `truncated?: boolean`, `row_cap?: number`, `preview_message?: string` — all OPTIONAL (absent when not truncated). No `any`.
- `ValidateStep.tsx` → when `response.truncated` is true, render an amber informational notice above the summary cards, styled like the existing warning banner (reuse the `IconAlertTriangle` + amber classes already used at `:44-58` for the blocked/clean banners — do NOT invent a new visual language).
- **Interaction with the existing clean banner** (`:53-58`): the unqualified "Sin errores. El archivo está listo para importar." MUST NOT render alone on a truncated response — it would assert the whole file is clean when 4000 rows were never read. When `truncated`, the clean banner's copy must be scoped to the previewed rows (e.g. "Sin errores en las filas revisadas.") or the truncation notice must visually supersede it. Pick one and keep it consistent.
- **Not blocking**: truncation must not disable "Continuar". `/import/execute` is deliberately NOT row-capped (`ImportController.php:71-76`) — the full file still imports. The notice informs; it does not gate.

**Rejected**: raising `validate_row_cap`. It is env-tunable (`DATA_IMPORT_VALIDATE_ROW_CAP`), but `WorkbookReader::parsePreview` materializes the sheet, so a higher cap trades a silent-correctness bug for a memory/latency cost on every validate — the wrong trade, and out of this change's scope.

**Test**: `ValidateStep.test.tsx` — notice renders with `truncated: true` + `preview_message`; absent otherwise.

## Data Flow

    ReferenceResolver::buildIssue()  ──(general:true)──▶ RowImporter::validate() ──flat──▶ ImportRunner::validate()
    RowImporter errors/email warning ──(no mark)──────────────────────────────────────────▶  ├─ collapseGeneralIssues() → rows
                                                                                             ├─ identity sets → batched diff → counters
                                                                                             ▼
    ImportController (422 loop + truncated keys unchanged) ──▶ IValidateResponse ──▶ ValidateStep ("N filas" cell)

## File Changes

| File | Action | Description |
|---|---|---|
| `backend/Modules/DataImport/app/Services/ReferenceResolver.php` | Modify | `general => true` in `buildIssue()` + docblock |
| `backend/Modules/DataImport/app/Services/ImportRunner.php` | Modify | collapse + identity collection + batched counters; remove `$blockedRows`; docblocks |
| `backend/Modules/DataImport/app/Services/RowImporter.php` | Modify | `max` rule + comment + 4 messages (`monto.max` + 3 `.string`); `validate()` docblock |
| `backend/Modules/DataImport/app/Services/TemplateGenerator.php` | Modify | LÉEME row 1 cell |
| `backend/database/seeders/csv/module_system.csv` | Modify | 1 row (D5) |
| `frontend/src/modules/import/schema.ts` | Modify | `IIssueRow` + `count?: number; general?: boolean` (ADDITIVE — `severity`/`column` keep their existing names, see spec Type Contract) ; `IValidateResponse` + `truncated?: boolean; row_cap?: number; preview_message?: string` (D7) |
| `frontend/src/modules/import/components/ValidateStep.tsx` | Modify | Fila cell renders count; truncation notice + scoped clean-banner copy (D7) |
| `frontend/src/modules/charge/ChargeListPage.tsx` | Modify | remove button `:356-358`, `navigate` decl `:168`, `useNavigate` import `:47` (sole usage — verified) |
| `frontend/src/modules/payment/PaymentListPage.tsx` | Modify | guarded button; `navigate` already present (`:37,:64`) |
| `backend/Modules/DataImport/tests/Unit/ImportRunnerValidateTest.php` | Create | collapse + counters + ordering |

## Interfaces / Contracts

```ts
export interface IIssueRow {
  row: number
  severity: IssueSeverity
  column: string
  message: string
  /** Rows sharing this collapsed general issue; absent on per-row issues. */
  count?: number
  /** True when the issue is catalog-level (grouped), set by the backend. */
  general?: boolean
}
```

`ValidateStep.tsx` Fila cell: `issue.count && issue.count > 1 ? `${issue.count.toLocaleString('es-MX')} filas` : issue.row`. Two-table severity split (`:22-23,:61-68`) unchanged. PaymentListPage button, between Descargar and Registrar pago:

```tsx
<PermissionGuard permission='create.data_import'>
  <Button variant='outline' onClick={() => navigate('/import')} className='flex items-center gap-2'>
    <Upload className='h-4 w-4' />
    Importar pagos
  </Button>
</PermissionGuard>
```

## Testing Strategy (Standard Mode — target: ZERO unintended breaks, evidence-backed)

| Invariant | Existing protection (verified green) | New tests |
|---|---|---|
| Per-row validate contract | `RowImporterValidateTest` (11) — `assertArrayHasKey` + scalar checks only (`:108,:241-243`), tolerant of the extra key | monto cap boundary (9999999.99 ok / 10000000.00 error, Spanish msg); 3 `.string` messages |
| Resolver shape | `ReferenceResolverTest` (12 methods) — `assertCount(1, $result['issues'])` + per-key `assertEquals` on `severity`/`column`/`row` (`:62-65,:133-135,:174-176,:204-206`); no whole-array equality anywhere → the added `general` key changes neither entry counts nor any asserted key | assert `general === true` on the 7 warnings |
| Endpoint contract | `ImportValidateTest` — counters asserted by `assertJsonStructure` only (`:190-198`); 422 regex (`:262-282`) untouched (errors flat); zero-write asserts (reads only) | grouped warning: 1 entry, `count=N`, `row`=first; errors stay per-row; counter honesty incl. existing-unit drift guard |
| Execute path | no exact-shape issue assertions module-wide (grep-verified); `isAutoCreateWarning` is `str_contains` on message | none needed |
| LÉEME | `TemplateGeneratorTest` does not pin row text (verified) | optional: pin new truthful text |
| FE | `ValidateStep.test.tsx` fixtures lack `count` → render unchanged | "N filas" when `count>1`; row number when absent/1 |

Commands: backend `php artisan test --filter=Import` (HOST php, ONE process), `vendor/bin/pint --dirty`; frontend `pnpm test`, `pnpm typecheck`, `pnpm lint`. Manual QA via `http://192.168.100.136:5173/` (origin-bound session).

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary.

## Migration / Rollout

No migrations. Post-deploy: `php artisan db:seed --class=ResetPermissionTableSeeder` (adds the pivot row; permissions/route/icon already wired). Rollback: revert commit + re-run the same seeder.

## Open Questions

None blocking.

- [x] RESOLVED — `Unit` relations verified: `unitType()` (`Unit.php:89`) and `tower()` (`:99`), camelCase (this model deviates from the project's snake_case belongsTo convention). Moot for the final mechanics: the ID-based diff (D3) reads FK columns directly and performs no relation calls.
- Noted, out of scope (pre-existing, same defect class as REQ-09 but not in the approved plan): `ReferenceResolver.php:103-104` warns "la unidad se importará sin estado" while execute actually applies the tenant's default status (`RowImporter.php:361-368`). Candidate for a follow-up text fix.
- Counters inherit preview scope under truncation via PHPDoc; the user-facing truncation notice is D7.
