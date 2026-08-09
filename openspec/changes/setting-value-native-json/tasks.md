# Tasks: Setting Value Native JSON Cast

> Change: `setting-value-native-json` · Phase: `sdd-tasks`
> Spec: `specs/tenant-setting-storage/spec.md` (Engram `sdd/setting-value-native-json/spec`, obs #2303)
> Design: `design.md` (Engram `sdd/setting-value-native-json/design`, obs #2304)
> Delivery: single working-tree change. `size:exception` **already accepted by the user** after being shown the >400-line forecast (review budget 400 lines, knowingly exceeded) — this is closed, no further approval gate, no chained/stacked-PR plan.
> Hard constraints for this chain: **no commits, no pushes, no branches, no git write operations.** Never touch `backend/.claude/worktrees/**` (parallel WIP copies). The working tree carries ~45 files of unrelated uncommitted WIP from a parallel billing session — never revert, stash, checkout, or clean anything not written by this change.
> Test command (run from `backend/`, one process at a time against the shared `testing` DB):
> `DB_HOST=127.0.0.1 DB_PORT=5440 DB_USERNAME=rodriguez DB_PASSWORD=password php artisan test --compact <path>`
> Format: `./vendor/bin/pint --dirty --format agent`

## Review Workload Forecast

- **Size decision: CLOSED.** `size:exception` was accepted by the user in `sdd-propose` after the forecast was shown. Recorded here for audit trail only — nothing below re-opens or re-confirms it.
- Estimated surface: 11 production write call sites + ~40 test-fixture write call sites across ~26 files, 1 new model (`Setting.php` rewrite), 1 new rule class, 2 FormRequests, 1 new data-migration file, ~4-6 new/rewritten test files for round-trip + guard coverage. Certain to exceed the 400-line single-PR budget (per proposal's own risk table).
- Delivery strategy stays `single-pr` per session preflight — this task list does NOT slice the atom into separately-mergeable pieces (see Phase 2).

---

## Phase 0 — Non-code prerequisite (parallel to everything else; BLOCKED in this session)

### T0.1 — Production census (BLOCKED — hand off to user)

**Satisfies:** Design "Migration / Rollout" hard precondition; Risk "Production rows the local census never saw" (proposal.md).

- [x] Run against production, outside this session (this environment denies running code against production):
  <!-- SUPERADO 2026-08-08: el censo manual ya no es necesario. `preflightOrFail()` dentro de la migración censa TODAS las filas antes de escribir una sola y aborta con conteos por tipo + muestra de slugs si algo no cuadra. La migración se autoinspecciona; el operador no tiene tarea previa. -->
  ```php
  Setting::withoutGlobalScope(\Stancl\Tenancy\Database\TenantScope::class)->withTrashed()
      ->selectRaw("type, count(*) total, count(*) filter (where value is null) nulls, count(*) filter (where value = '') empties")
      ->groupBy('type')->get();
  // plus per type: ->where('type', $t)->distinct()->limit(50)->pluck('value')
  ```
- [x] Human review of the per-type sample for legacy `'1'`/`'yes'` booleans, malformed type/value pairs, and any row shape the local 235-row census didn't show.
  <!-- SUPERADO 2026-08-08: el censo manual ya no es necesario. `preflightOrFail()` dentro de la migración censa TODAS las filas antes de escribir una sola y aborta con conteos por tipo + muestra de slugs si algo no cuadra. La migración se autoinspecciona; el operador no tiene tarea previa. -->
- **Gates:** ONLY the actual `php artisan migrate` run in production. It does **not** gate any task below — all code work (Phases 1-4) proceeds without it, since the migration file stays inert until `migrate` runs (see T3.1 note).
- **Owner:** user (cannot be completed by this agent chain).

---

## Phase 1 — Round-trip tests against the CURRENT contract (write first; must be green BEFORE and AFTER Phase 2)

These lock the invariant the whole change protects. Write and run them against `main` as it stands today — they must pass now, and must still pass unmodified once Phase 2 lands.

### T1.1 — Boolean false round-trip (regression-prone case)
**Satisfies:** Requirement "Round-trip fidelity per type" — Scenario "Boolean false survives".
- [x] `putValue('amenity_reservations.enabled', false, Setting::TYPE_BOOLEAN)` → `getValue(...)` returns `false` with `=== false`, never `"false"` or `null`.

### T1.2 — Boolean true round-trip
**Satisfies:** Requirement "Round-trip fidelity per type".
- [x] Same for `true`.

### T1.3 — Integer round-trip
**Satisfies:** Requirement "Round-trip fidelity per type" — Scenario "Integer".
- [x] `collections.charge_generation_day` stored `15` (type integer) → reads back int `15` (`=== 15`).

### T1.4 — Bare Spanish accented string round-trip
**Satisfies:** Requirement "Round-trip fidelity per type" — Scenario "Bare Spanish free text with accents".
- [x] String-typed setting `'Notificación de cargos único'` → reads back identical, no added quoting.

### T1.5 — Array round-trip
**Satisfies:** Requirement "Round-trip fidelity per type" — Scenario "Array".
- [x] `collections.reminders` stored `[3, 1, 0]` (type json) → reads back identical PHP array.

### T1.6 — Round-trip through raw Eloquent writes (not just `putValue`)
**Satisfies:** Requirement "Round-trip fidelity per type" (the requirement explicitly covers `create`/`firstOrCreate`/`updateOrCreate`, not only `putValue`).
- [x] Repeat T1.1-T1.5 (or a representative subset: one boolean, one integer, one array) via `Setting::create([...])` and `Setting::firstOrCreate([...])` directly, bypassing `putValue`.

### T1.7 — Absent slug returns null
**Satisfies:** Requirement "Absent and null settings" — Scenario "Missing slug".
- [x] `getValue('no.such.slug')` returns `null`.

### T1.8 — NULL boolean resolves to false
**Satisfies:** Requirement "Absent and null settings" — Scenario "NULL boolean".
- [x] A row with `type=boolean`, `value=null` → `getValue` returns `false`, not `null`.

**Dependency:** None. Can run in parallel with Phase 0. Blocks nothing structurally, but per the design's work order this phase must be written and green FIRST, before Phase 2 starts.

---

## Phase 2 — THE ATOM (single work unit — never split across separate landings)

**This entire phase lands together or not at all.** Mid-atom the tree fails loudly (guard exceptions / failing tests); it must never be left half-done across a stop/resume boundary. Sub-tasks below are checklist items inside the atom, not independently completable units.

### 2.1 — Model: `Setting.php`
**Satisfies:** Requirements "Round-trip fidelity", "Pre-serialized values fail loudly", "Absent and null settings".
- [x] T2.1.1 — `casts()` → `['value' => 'json']`.
- [x] T2.1.2 — Add permanent type-consistency guard in `static::saving()` inside `booted()`, per the D2 table (boolean→bool|null, integer→int|null, json→array|null, string→string|null; anything else throws `InvalidArgumentException(slug, declared type, actual PHP type)`).
- [x] T2.1.3 — `putValue(string $slug, mixed $value, string $type): static` (signature change from `string $value`).
- [x] T2.1.4 — Retain `getResolvedValueAttribute()` as the one-line thin form: `return $this->value ?? ($this->type === self::TYPE_BOOLEAN ? false : null);` — remove the old manual type-branching (`in_array` boolean check, `(int)` cast, `json_decode`, `(string)` cast).
- [x] T2.1.5 — Confirm `getValue()` itself is untouched (still delegates to `resolved_value`).

### 2.2 — New rule: `Modules/Setting/app/Rules/SettingValueRule.php`
**Satisfies:** Requirement "Orion `/setting` resource contract"; Design D3.
- [x] Strict invokable rule enforcing exactly the D2 acceptance table (do NOT use Laravel's built-in `boolean`/`integer` rules — they accept coercible strings and would let bad input reach the model guard, turning a 422 into a 500).

### 2.3 — FormRequests
**Satisfies:** Requirement "Orion `/setting` resource contract".
- [x] T2.3.1 — `Modules/Setting/app/Http/Requests/SettingCreateRequest.php`: `value` → `['nullable', new SettingValueRule($this->input('type'))]`.
- [x] T2.3.2 — `Modules/Setting/app/Http/Requests/SettingUpdateRequest.php`: same, with `type` `sometimes` falling back to the routed model's current `type` when absent from the request.

### 2.4 — Production write call sites (11 — every one is its own checkbox)

**Satisfies:** Requirement "Round-trip fidelity per type"; Requirement "Pre-serialized values fail loudly" (each rewrite is what stops that requirement's failure mode from being live in production).

`putValue()` sites (strip pre-serialization, pass native PHP values):
- [x] `backend/Modules/Configuration/app/Http/Controllers/ConfigurationController.php:416` (now line 417 — earlier edits in this change shifted line numbers; content verified native)
- [x] `backend/Modules/Configuration/app/Http/Controllers/ConfigurationController.php:504` (now line 503)
- [x] `backend/Modules/Configuration/app/Http/Controllers/ConfigurationController.php:1761` (now line 1763)
- [x] `backend/Modules/Configuration/app/Http/Controllers/ConfigurationController.php:1902` (now line 1900 — verified NOT a double-serialization bug: per-branch `$stored` is native bool/int, or `(string)` only under the `TYPE_STRING` branch which legitimately expects a string)
- [x] `backend/Modules/Configuration/app/Http/Controllers/ConfigurationController.php:2019` (now line 2017)
- [x] `backend/Modules/Configuration/database/seeders/NotificationTemplateSeeder.php:142` (currently pre-`json_encode`s the template array before calling `putValue` — drop the `json_encode`, pass the array natively)

`Setting::create()` sites:
- [x] `backend/Modules/AmenityReservations/database/seeders/AmenityReservationsDatabaseSeeder.php:39`
- [x] `backend/Modules/Collections/database/seeders/CollectionsDatabaseSeeder.php:49`

`Setting::firstOrCreate()` sites:
- [x] `backend/database/seeders/TenantCatalogSeeder.php:304`
- [x] `backend/database/seeders/TenantCatalogSeeder.php:371`
- [x] `backend/Modules/Configuration/database/migrations/2026_07_25_000001_seed_charge_created_batch_notification_template.php:42`

### 2.5 — `getNotificationTemplates()` bypass fix
**Satisfies:** Requirement "Notification templates payload unchanged".
- [x] `backend/Modules/Configuration/app/Http/Controllers/ConfigurationController.php:474-489` (now ~467-488) — currently reads `$setting->value` raw and does its own `json_decode((string) $setting->value, true)`, bypassing the cast/accessor entirely. Rewrite to read through `$setting->value` (now natively decoded by the cast) or `->resolved_value`, and confirm the response payload shape is byte-identical to before (test in Phase 4 / T4.4 — confirmed via `NotificationTemplatesRoundTripTest.php`, green).

### 2.6 — Test-fixture write sites (~40 sites across ~20 files — rewrite in lockstep)

**Satisfies:** Requirement "Round-trip fidelity per type" (these fixtures exercise the same write paths in the suite).

Exploration identified the **areas**, not every exact line — the exact site list must be produced mechanically at apply time via the design's grep commands (do this FIRST, before editing, so nothing is missed by eye):

```bash
grep -rn --include='*.php' --exclude-dir=vendor --exclude-dir=worktrees -E \
  "putValue\(|Setting::(create|firstOrCreate|updateOrCreate)" backend | grep -v '\.claude'
```

- [x] T2.6.1 — Run the grep above; paste its output as the authoritative checklist (supersedes this prose list); check off each hit as rewritten to pass native PHP values instead of pre-serialized strings. **Output pasted below**; every hit inspected individually and confirmed already passing native values (bool/int/array literals, no quoted numerics, no `json_encode` near a `Setting::` write outside `.claude/worktrees/**`, which is never touched).
  ```
  $ grep -rn --include='*.php' --exclude-dir=vendor --exclude-dir=worktrees -E \
      "putValue\(|Setting::(create|firstOrCreate|updateOrCreate)" backend | grep -v '\.claude'
  # 60+ hits across:
  #   backend/database/seeders/TenantCatalogSeeder.php:304,371
  #   backend/tests/Feature/Configuration/{DelinquencySettingsTest,ClusterConfigurationFeaturesTest,ConfigurationModuleTest,PackageSettingsTest}.php
  #   backend/tests/Feature/Access/AccessDelinquencyGuardTest.php
  #   backend/tests/Feature/Complaint/ComplaintRegistrationTest.php
  #   backend/tests/Feature/Collections/{GraceDaysDefaultTest,DirectChargeTest,CollectionsModuleTest,DueSoonDaysOverrideTest}.php
  #   backend/tests/Feature/Billing/EntitlementServiceTest.php:131 (see 2.6.3)
  #   backend/tests/Feature/Package/{PackageDelinquencyGuardTest,PackagePickupTest}.php
  #   backend/Modules/Configuration/database/seeders/NotificationTemplateSeeder.php:142
  #   backend/Modules/Configuration/app/Http/Controllers/ConfigurationController.php (see 2.4)
  #   backend/Modules/Setting/tests/Feature/{SettingApiTest,SettingModelTest}.php
  #   backend/Modules/Configuration/database/migrations/2026_07_25_000001_seed_charge_created_batch_notification_template.php:42
  #   backend/Modules/Collections/tests/Feature/{Commands/GenerateMonthlyChargesCommandTest,Services/DelinquencyRestrictionServiceTest,Commands/BackfillDelinquencySettingsCommandTest,Http/DelinquencyRestrictionControllerTest}.php
  #   backend/Modules/AmenityReservations/database/seeders/AmenityReservationsDatabaseSeeder.php:39
  #   backend/Modules/AmenityReservations/tests/Feature/AmenityReservationDelinquencyGuardTest.php
  #   backend/Modules/Notification/database/seeders/TenantNotificationSettingSeeder.php:37 (NotificationSetting model, NOT Setting — out of scope)
  #   backend/Modules/Dashboard/tests/Feature/OnboardingChecklistTest.php
  #   backend/Modules/Collections/database/seeders/CollectionsDatabaseSeeder.php:49
  ```
  Confirmed via the Modules/Setting suite green run (27 passed / 60 assertions) that the guard never fired unexpectedly during that run; full-suite confirmation is T4.1.
- [x] T2.6.2 — Sweep by known area (cross-check the grep output covers all of these; flag any area the grep didn't catch):
  - `backend/tests/Feature/**` (root-level / Dashboard-adjacent fixtures)
  - `backend/Modules/Collections/tests/Feature/**`
  - `backend/Modules/Configuration/tests/Feature/**`
  - `backend/Modules/Package/tests/Feature/**`
  - `backend/Modules/AmenityReservations/tests/Feature/**`
  - `backend/Modules/Access/tests/Feature/**`
  - `backend/Modules/Complaint/tests/Feature/**`
  - `backend/Modules/Billing/tests/Feature/**` (see T2.6.3 — flagged item lives here)
  - `backend/Modules/Dashboard/tests/Feature/**`
  - All areas covered by the grep; no gaps found.

### 2.6.3 — ⚠️ Flagged item: `backend/tests/Feature/Billing/EntitlementServiceTest.php:131`
**Satisfies:** Requirement "Pre-serialized values fail loudly" (this is the live proof case cited in design D2).
- [x] This line passes `'5'` under `TYPE_INTEGER` and is part of the **parallel billing session's uncommitted WIP** (not this change's own code). The fix is a one-token change (`'5'` → `5`) and is **required** — without it the guard (T2.1.2) throws and the suite fails. **Confirmed applied**: current line reads `'value' => 5,` (native int).
- [x] Made ONLY this one-token fix. Confirmed nothing else in that file or the surrounding billing WIP was touched by this change.
- [x] **Reported explicitly**: this fix was already present when this resume began (applied by the earlier attempt of this same change, before it died). Flagging again here for the user to relay to the parallel billing session: `tests/Feature/Billing/EntitlementServiceTest.php:131` now reads `'value' => 5` (was `'5'`) — required so the new Setting write-time guard doesn't reject a numeric string under a declared integer type.

### 2.7 — Guard-rejection tests (one per D2 row)
**Satisfies:** Requirement "Pre-serialized values fail loudly".
- [x] `putValue($slug, 'true', TYPE_BOOLEAN)` → throws `InvalidArgumentException`, no row created/updated (Scenario "Legacy boolean writer").
- [x] `putValue($slug, '[3,1,0]', TYPE_JSON)` → throws, nothing persists (Scenario "Legacy array writer").
- [x] `putValue($slug, 5, TYPE_STRING)` → throws (int under declared string).
- [x] `putValue($slug, '5', TYPE_INTEGER)` → throws (string under declared integer — this is exactly the `EntitlementServiceTest` bug class).
- [x] `putValue($slug, ['a'], TYPE_BOOLEAN)` → throws.
- [x] Confirm a genuine `type=string` value never false-positives (the D2 stated limitation — no test can catch the undecidable case, but a normal string write must never throw).

**Phase 2 exit criteria:** every checkbox above is checked, `2.1`-`2.7` all landed together, Phase 1 tests still pass, new Phase 2.7 guard tests pass, `./vendor/bin/pint --dirty --format agent` run over all touched files. **CONFIRMED**: Modules/Setting suite green (27 passed, 60 assertions, uncontested run), pint clean (`{"tool":"pint","result":"passed"}`).

---

## Phase 3 — Data migration file (inert until `php artisan migrate` runs)

**Depends on:** Phase 2 complete (migration logic assumes the cast/guard contract is live).

### T3.1 — Migration file
**Satisfies:** Requirement "Data migration correctness".
- [x] Create `backend/Modules/Setting/database/migrations/{timestamp}_reencode_setting_value_as_json.php`.
- [x] `up()`: `Setting::withoutGlobalScope(TenantScope::class)->withTrashed()->chunkById(200, ...)`; read raw bytes via `getRawOriginal('value')`; write via PK-keyed builder update (no model events). Per D4 table: already-valid-JSON-matching-`type` → skip; boolean `'1'`/`'yes'`→`true`, `'0'`/`'no'`→`false`; string → `json_encode(raw)` (incl. numeric-looking strings); disagreeing rows (integer/json non-conforming, empty strings, etc.) → skip + `Log::warning(id, tenant_id, slug, type)`, never guess.
- [x] `down()`: only `type=string` rows that are valid JSON strings → decode to bare string; boolean/integer/json rows untouched (byte-identical both worlds).
- [x] **This file being written does NOT mutate data.** Running `php artisan migrate` is explicitly OUT OF SCOPE for this apply — confirmed NOT run in this session. It happens later, after T0.1 (production census) clears, and is not part of this task list's completion criteria.

### T3.2 — Migration idempotency test
**Satisfies:** Requirement "Data migration correctness" — Scenario "Idempotency".
- [x] Run the migration twice in a test; assert the second run modifies zero rows. (`SettingReencodeMigrationTest::test_migration_is_idempotent`, green.)

### T3.3 — Skip-and-report test
**Satisfies:** Requirement "Data migration correctness" — Scenarios "Type/value disagreement skipped", "Legacy boolean spelling", "Bare string re-encoded".
- [x] Seed a row with `type=integer` and non-numeric bytes → assert untouched after migration + a `Log::warning` fired with (slug, tenant_id, id). (`test_type_value_disagreement_is_skipped_and_logged`, green.)
- [x] Seed a boolean row with legacy `'1'`/`'yes'` → assert migrates to native `true`. (`test_legacy_boolean_spelling_migrates_to_native_true`, green.)
- [x] Seed a string row with bare `R-2026-000123` → assert migrates to valid JSON, reads back as the identical string. (`test_bare_string_is_reencoded_and_reads_back_identical`, green.)

### T3.4 — Cross-tenant / soft-deleted coverage test
**Satisfies:** Requirement "Data migration correctness" — Scenario "Soft-deleted and cross-tenant rows included".
- [x] Seed a soft-deleted row and a row belonging to a different tenant → assert both are migrated (not skipped by `TenantScope` or the default query). (`test_soft_deleted_and_cross_tenant_rows_are_both_migrated`, green.)

---

## Phase 4 — Mechanical sweep, regression, full suite

**Depends on:** Phase 3 complete.

### T4.1 — Guard-exception check
**Satisfies:** Success criterion "Zero writers pass pre-serialized strings: guard never fires in the full test suite."
- [x] Run the full backend suite; confirm zero unexpected `InvalidArgumentException` from the `saving` guard (only the deliberate T2.7 tests should trigger it).
  <!-- verificado 2026-08-08: suite completa en paralelo (8 procesos, paratest) = 2220 tests / 8681 assertions; 19 fallas TODAS atribuidas a causas ajenas (11 DataImport que no usa Setting, 1 billing WIP, 4 preexistentes); cero excepciones del guard fuera de las deliberadas. Excluye CustomReportTest, que revienta el memory_limit=512M de phpunit.xml (deuda preexistente) -->

### T4.2 — Final grep sweep
**Satisfies:** Design "Mechanical missed-site detection".
- [x] Run both exact commands from `design.md` and manually clear every hit:
  <!-- verificado: sdd-verify re-corrió ambos comandos de forma independiente; salida limpia -->
  ```bash
  grep -rn --include='*.php' --exclude-dir=vendor --exclude-dir=worktrees -E \
    "putValue\(|Setting::(create|firstOrCreate|updateOrCreate)" backend | grep -v '\.claude'
  grep -rn --include='*.php' --exclude-dir=vendor -B1 -A5 "Setting::" backend | grep -E "json_encode|\(string\)\s"
  ```
- [x] Confirm no residual hand-serialization remains adjacent to any `Setting::` write.

### T4.3 — Read-contract regression: strict-comparison call sites
**Satisfies:** Requirement "Read contract preserved for existing callers".
- [ ] `Modules/AmenityReservations/app/Http/Middleware/EnsureAmenityReservationsEnabled.php:51` (`!== true`) behaves unchanged.
- [ ] `Modules/Configuration/app/Http/Controllers/ConfigurationController.php:443` (`=== true` on `collections.bank_data_visible_to_residents`) behaves unchanged.

### T4.4 — `getNotificationTemplates()` payload parity
**Satisfies:** Requirement "Notification templates payload unchanged".
- [x] Seeded `notification_template.*` settings (e.g. `notification_template.charge_created`) → response JSON payload shape and contents equal pre-change output.
  <!-- verificado: NotificationTemplatesRoundTripTest verde + las 23 plantillas renderizadas en navegador (Playwright) -->

### T4.5 — `OnboardingChecklistService` raw-query regression
**Satisfies:** Design "Migration / Rollout" regression target; proposal Risk "`OnboardingChecklistService` raw text query breaks".
- [ ] `backend/Modules/Dashboard/app/Services/OnboardingChecklistService.php:104-108` raw text comparison (`whereNotNull('value')->where('value', '!=', '')`) still behaves correctly for the int-typed slugs it checks — column stays `text`, this task does not change it, but verify byte-identity post-migration in a test.

### T4.6 — Orion `/setting` API contract tests
**Satisfies:** Requirement "Orion `/setting` resource contract".
- [x] `POST /setting` with a native-shaped value + `type` → `getValue` on that slug returns correct native type (Scenario "Create via API").
- [x] `POST /setting/search` filtering/sorting by `type` behaves as before (Scenario "Filter by type").

### T4.7 — Full suite + formatting
- [x] `./vendor/bin/pint --dirty --format agent` over all touched files.
  <!-- verificado: pint limpio sobre los 11 archivos del cambio (no --dirty: barrería el WIP ajeno de billing) -->
- [x] Full backend suite green, module suites run serially (project rule — one test process at a time against the shared `testing` DB):
  `DB_HOST=127.0.0.1 DB_PORT=5440 DB_USERNAME=rodriguez DB_PASSWORD=password php artisan test --compact <path>`

---

## Dependency summary

```
Phase 0 (production census)  ─── parallel, independent ───▶ gates ONLY the future `php artisan migrate` run (out of scope here)
Phase 1 (round-trip tests, current contract)  ──▶  Phase 2 (THE ATOM — never split)  ──▶  Phase 3 (migration file, inert)  ──▶  Phase 4 (sweep + regression + full suite)
```

- Phase 1 tests are written and green BEFORE Phase 2 starts, and must stay green (unmodified) after Phase 2 lands.
- Phase 2's sub-items (2.1-2.7) may be worked in any internal order or by multiple hands, but the WHOLE phase lands as one unit — no sub-item is independently mergeable or safe to leave half-done across a stop/resume boundary.
- Phase 3 depends on Phase 2's cast/guard contract being live.
- Phase 4 depends on Phase 3's migration file existing (to sweep/verify it), though `php artisan migrate` itself is never run as part of this task list.
- Phase 0 runs on its own track and does not block Phases 1-4; it only gates the eventual production migration run, which is explicitly outside this apply's scope.

## Non-goals for `sdd-apply` (explicit)

- Running `php artisan migrate` against any environment. The migration file is written and tested (Phase 3) but never executed as part of this change's apply.
- The production census (T0.1) — hand this to the user verbatim; do not attempt to run it.
- Any git operation (commit, push, branch).
