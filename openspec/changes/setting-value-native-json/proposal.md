# Proposal: Setting Value Native JSON Cast

> Change: `setting-value-native-json` · Phase: `sdd-propose` · Artifact store: hybrid
> Input: `exploration.md` (Engram `sdd/setting-value-native-json/explore`, obs #2301)
> Delivery: single working-tree change, `size:exception` explicitly accepted by the user (>400-line budget knowingly exceeded). No commits/pushes/branches in this chain.

## Intent

`setting.value` is a generic per-tenant key-value store where every writer hand-serializes (bool→`'true'`, int→`(string)$n`, array→`json_encode`) and every reader hand-deserializes by branching on the sibling `type` column. This duplicated serialization logic is spread across ~50 call sites in ~26 files; each new writer must re-implement it correctly or silently corrupt a feature gate. One reader (`ConfigurationController::getNotificationTemplates()`, lines 474-489) already bypasses the accessor with its own raw `json_decode`. The cost is ongoing: every Setting-touching feature pays a serialization tax and a correctness risk that Laravel's native `'value' => 'json'` cast eliminates — JSON is self-describing, proven by tinker round-trip for all four stored types.

## Scope

### In Scope
- `Setting::casts()` → `['value' => 'json']`; remove the manual `getResolvedValueAttribute()` type-branching (keep `getValue()`/`putValue()` as thin wrappers preserving the current read contract, incl. the `null`→`false` boolean special case).
- `putValue()` signature: `string $value` → `mixed $value`; strip pre-serialization from all callers.
- Rewrite all 11 production write call sites (6 `putValue`, 2 `create`, 3 `firstOrCreate`) and ~40 test-fixture write sites (~26 files total) in lockstep to pass native PHP values.
- Fix the `getNotificationTemplates()` raw `json_decode` bypass to read through the cast.
- One-time, idempotent, cross-tenant data migration re-encoding existing rows per their current `type`.
- Round-trip regression test per type (string/boolean/integer/json) asserting write→read fidelity through the public API (`putValue`/`getValue` and raw model create).

### Out of Scope (explicit non-goals)
- NO `jsonb` column conversion — column stays `text`. (`OnboardingChecklistService.php:104-108` runs a raw text comparison Postgres won't implicitly cast against `jsonb`; `ALTER … USING value::jsonb` hard-fails transactionally on any non-JSON row.)
- NO dropping the `type` column — kept as authoring/validation metadata (FormRequests + live Orion `/setting` filterable/sortable contract) and as the key that makes the data migration reversible.
- NO change to the read contract seen by callers of `Setting::getValue()` — same resolved PHP types out.
- NO frontend changes (no frontend module consumes this resource).
- NO PR-splitting plan — `size:exception` accepted; internal work order below replaces slicing.

## Capabilities

### New Capabilities
- `tenant-setting-storage`: how `Setting` stores, serializes, and resolves typed per-tenant key-value data — native JSON cast on `value`, `type` as authoring metadata, `getValue()`/`putValue()` contract, migration/remediation rules for pre-cast rows.

### Modified Capabilities
- None (no existing specs in `openspec/specs/`).

## Approach

Native Eloquent `'json'` cast on a `text` column (approach 2 from exploration — the option the user chose). The defining constraint: **a half-migrated state silently corrupts data** — a writer still passing a pre-serialized string through the new cast double-encodes (`'true'` → stored `"true"` JSON *string* → read back as PHP string, flipping strict `=== true` feature gates to always-false, with no crash). The change is therefore delivered as one atomic cutover of cast + all writers + data migration, guarded by the safety contract below.

### Cutover safety contract

| Layer | What it guarantees | What it does NOT catch |
|---|---|---|
| `putValue(string $slug, mixed $value, string $type)` signature change | Old call shape still type-checks (string ⊂ mixed) — so the signature alone proves nothing; safety comes from the layers below | Everything — this layer is ergonomic, not protective |
| Temporary write guard in `putValue()`/model `saving`: when `type` ≠ `string`, reject a `$value` that is a string decoding to valid JSON of the declared type (i.e. a pre-serialized payload) with a thrown exception | Converts the *silent* double-encode into a *loud* failure in dev/tests for every `putValue` and Eloquent-event write | Nothing — but it fires only when the bad path executes |
| Grep-able audit checklist in tasks: every one of the 11 production + ~40 test write sites listed by file:line from exploration, checked off as rewritten | No writer is forgotten during apply | New writers added after the change (mitigated by guard + tests) |
| Round-trip fidelity tests per type, through `putValue`/`getValue` AND raw `Setting::create`/`firstOrCreate`/`updateOrCreate` | Regression net for exactly the paths the type system cannot see (hand-built attribute arrays pass no type check) | — |
| Post-apply repo-wide grep assertion: zero remaining `json_encode(`/`(string)` pre-serialization adjacent to Setting writes | Mechanical final sweep before verify | — |

The write guard is cheap (one `json_decode` probe on write), keyed off the retained `type` column, and may stay permanently or be removed in a later change once production has soaked.

### Data migration intent
- Re-encode each row's raw text into valid JSON per its current `type`: bare strings → JSON-quoted; legacy boolean spellings (`'1'`/`'yes'` per the old permissive check) → JSON `true`/`false`; integer/json rows are already valid JSON byte-for-byte → untouched.
- **Idempotent**: a row already valid JSON *consistent with its `type`* is skipped; re-running is a no-op.
- **Cross-tenant + trash-aware**: `Setting::withoutGlobalScope(TenantScope::class)->withTrashed()->chunkById(...)` — never per-tenant iteration, never missing soft-deleted rows (15 exist locally).
- **Rows whose bytes disagree with their `type`** (e.g. `type=integer` but non-numeric text): do NOT guess — leave the row untouched, log slug/tenant/id to a remediation report for human review. The read path degrades gracefully (cast yields the decoded value or null), never crashes.
- **Reversible while `type` is retained**: `down()` re-serializes per `type` back to the legacy bare format. This reversibility is a load-bearing reason `type` stays.

### Rollout / verification
- **Production census is a hard precondition** — the clean census (235 rows, no NULL/empty-string values) is LOCAL ONLY; production has NOT been censused. Before the migration runs anywhere real: `Setting::withoutGlobalScope(TenantScope::class)->withTrashed()->selectRaw("type, count(*), count(*) filter (where value is null), count(*) filter (where value = '')")->groupBy('type')` (tinker or a one-off artisan command), plus a per-type sample of distinct raw values to detect legacy `'1'`/`'yes'` booleans and malformed pairs.
- Local: full backend suite + the new round-trip tests; migration run twice to prove idempotency; `OnboardingChecklistService` raw query verified byte-identical for the int-typed slugs it checks.

### Internal order of work (tree never silently-corrupting if stopped midway)

1. **Tests first**: round-trip fidelity tests written against the CURRENT contract (they pass today, keep passing after — the invariant).
2. **Write guard + `mixed` signature + cast + accessor removal + all ~50 writer rewrites + bypass fix in ONE step** — this is the atom; it is never split. Until this step lands the tree is fully old-world; after it, fully new-world with the guard armed.
3. **Data migration file** (inert until `php artisan migrate` is run — writing the file does not mutate data).
4. Post-apply grep sweep + full suite.

Stopping after 1 = harmless (extra tests). Stopping mid-2 leaves failing tests/guard exceptions — loud, not silent. Stopping after 2 = code correct, old rows unread-until-migrated but the cast degrades to null rather than corrupting, and the migration file is the only pending piece. No window exists where writes corrupt silently.

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `backend/Modules/Setting/app/Models/Setting.php` | Modified | Cast, accessor removal, `getValue()`/`putValue(mixed)`, temporary write guard |
| `backend/Modules/Setting/app/Http/Requests/Setting{Create,Update}Request.php` | Modified | `value` validation for native shapes (API contract change on `/setting`) |
| `backend/Modules/Configuration/app/Http/Controllers/ConfigurationController.php` | Modified | 5 `putValue` sites, `getNotificationTemplates()` bypass fix |
| `backend/Modules/Configuration/database/seeders/NotificationTemplateSeeder.php` | Modified | Drop pre-`json_encode` |
| `backend/database/seeders/TenantCatalogSeeder.php` | Modified | 2 `firstOrCreate` blocks |
| `backend/Modules/{AmenityReservations,Collections}/database/seeders/*` | Modified | 2 `Setting::create` sites |
| `backend/Modules/Configuration/database/migrations/2026_07_25_000001_*.php` | Modified | Legacy `firstOrCreate` |
| `backend/Modules/Setting/database/migrations/` (new file) | New | One-time data remediation migration |
| `backend/tests/**`, `backend/Modules/*/tests/**` (~20 files) | Modified | ~40 fixture write sites + new round-trip tests |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| **Silent double-encoding** — a missed writer stores `"true"` (JSON string), strict `=== true` gates flip to always-false with no crash | Med (without controls) → Low | The full safety contract above: write guard (loud failure), per-site audit checklist, round-trip tests over the untyped write paths, final grep sweep. Detection strategy: guard exception in dev/tests; in soak, a scheduled/manual census query counting `value` rows that are JSON strings decoding to `'true'/'false'/numeric` under a non-string `type` |
| Production rows the local census never saw (empty strings, legacy `'1'`/`'yes'`, malformed pairs) | Med | Mandatory production census before migrating; migration skips-and-logs disagreeing rows instead of guessing; idempotent re-run after remediation |
| `/setting` Orion FormRequest change is a live public API contract change | Low | `type` column and filterable/sortable surface unchanged; only `value` shape validation adapts; no frontend consumer exists (verified in exploration) |
| `OnboardingChecklistService` raw text query breaks | Low | Column stays `text`; the int-typed slugs it checks are byte-identical post-migration; explicit verification step |
| Review budget exceeded (~50 sites / ~26 files) | Certain | `size:exception` accepted by the user with the forecast shown; internal work order keeps the tree safe midway |

## Rollback Plan

Pre-migrate (working tree only): revert the code changes; no data was touched. Post-migrate: run the migration's `down()`, which re-serializes rows to the legacy bare format per their retained `type`, then revert code. Rows the migration skipped were never modified. Rollback stays clean precisely because `type` is kept and the migration is per-row and logged.

## Dependencies

- Production data census (query above) MUST run before the data migration executes outside local dev.
- Backend tests run on host php against the shared `testing` Postgres DB — one test process at a time (project rule).

## Success Criteria

- [ ] `Setting::getValue()` returns identical resolved values for all four types, before vs after (round-trip tests green).
- [ ] Zero writers pass pre-serialized strings: guard never fires in the full test suite; grep sweep finds no residual hand-serialization at Setting write sites.
- [ ] Data migration is provably idempotent (second run = 0 rows changed) and logs, not mutates, type/value-disagreeing rows.
- [ ] `getNotificationTemplates()` reads through the cast; templates render unchanged.
- [ ] `OnboardingChecklistService` checklist behavior unchanged.
- [ ] Full backend suite green (module suites run serially per project rule).
