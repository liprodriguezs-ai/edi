# Tasks: EDI Instrument Catalog

Change: `edi-instrument-catalog`

## Delivery forecast (review workload guard)

Decision needed before apply: Yes
Chained PRs recommended: Yes
400-line budget risk: High

Thirteen migrations, 15 models, seeder + verify machinery, ~2,900 committed CSV data rows (derived) plus ~430 hand-written rule/message rows (authored, review-critical), registration wiring and a React inspector far exceed one 400-line PR. Recommended chain (each slice autonomous, verifiable, revertible): **PR1** = Phases 0–2 (schema + catalogs + immutability), **PR2** = Phase 3 (content data + seeder), **PR3** = Phases 4–5 (verify command + registration + API), **PR4** = Phases 6–7 (inspector + closure). Resolve open decisions Q1–Q6 (design.md §11) before starting Phase 3 — the hand-written CSVs encode them.

All paths relative to `/Users/rodriguez/code/edi-app/`. Backend tests run from `backend/` with the host command in `openspec/config.yaml`. Do NOT create git commits from this pipeline.

---

## Phase 0 — Scaffolding

- [x] 0.1 Scaffold `backend/Modules/Instrument/` cloning the `backend/Modules/ChildRecord/` layout (`module.json`, `composer.json`, `app/{Models,Http/Controllers,Observers,Services,Console}`, `database/{migrations,seeders,factories}`, `routes/api.php`, `tests/Feature`).
- [x] 0.2 Register in `backend/modules_statuses.json` (`"Instrument": true`); confirm provider boot with `php artisan route:list | grep instrument` after Phase 5.

## Phase 1 — Support catalogs (central, fixed UUID)

- [x] 1.1 Migrations `backend/Modules/Instrument/database/migrations/`: `..._create_result_color_table.php`, `..._create_development_area_table.php`, `..._create_application_position_table.php` — table comment inside the closure, `BaseModel::columns($table, false)`, columns per design §3.1 (every own column `->comment()`), unique keys on `key`/`code` partial on `deleted_at IS NULL`.
- [x] 1.2 Models `ResultColor`, `DevelopmentArea`, `ApplicationPosition` with the fixed UUID constants from design §3.1 (`ed200001…/ed200002…/ed200003…`).
- [x] 1.3 `backend/database/seeders/CatalogSeeder.php`: add `seedResultColors()`, `seedDevelopmentAreas()` (8 rows with `scoring_kind`, `is_development_axis`, `sort_order`), `seedApplicationPositions()` (6 rows, `legacy_id` 1–6) via `upsertCatalog`.
- [x] 1.4 Feature test: catalogs seeded idempotently with fixed ids; `development_area` has exactly one `counter` row (ALE) and five `is_development_axis` rows (MG/MF/LE/SO/CO). — `Modules/Instrument/tests/Feature/CatalogSeedingTest.php`

## Phase 2 — Registry + versioned content schema

- [x] 2.1 Migrations `..._create_instrument_table.php` and `..._create_instrument_version_table.php` per design §3.2, including partial uniques `(instrument_id, key)` and one-default-per-instrument `(instrument_id) WHERE is_default AND deleted_at IS NULL`.
- [x] 2.2 Migrations for the 8 content tables per design §3.3 (`age_group`, `screening_item`, `screening_item_option`, `risk_factor`, `head_circumference_reference`, `area_scoring_rule`, `global_scoring_rule`, `global_result_message`): every own column `->comment()` (money-free; percentile columns note "integer millimeters (mm, cm × 10)"), all partial unique indexes from design §3.3 via `DB::statement`.
- [x] 2.3 Models `Instrument`, `InstrumentVersion` (constants `EDI_LEGACY_2013`/`EDI_2022_2ED`, `casts` for `published_at`), and the 8 content models: relations per design §8, `$hidden = ['legacy_id']` where the column exists, `condition`/`referral_items` cast to `array`. Deviation: all `BelongsTo` relation method names use project convention (snake_case, e.g. `age_group()`/`development_area()`/`result_color()`), not the camelCase implied nowhere in design but present in an early draft — matches `laravel-backend-architecture` hard rule.
- [x] 2.4 `app/Support/InstrumentContentUuid.php`: namespace constant `ed1c0000-0000-4000-8000-000000000001` + `for(string $versionKey, string $table, string $naturalKey): string` using `Ramsey\Uuid::uuid5` (design §4).
- [x] 2.5 Observers: `InstrumentContentObserver` (`saving`/`deleting`/`restoring` → `ImmutableInstrumentVersionException` when the row's version is published) registered for the 8 content models + `AgeGroup` self-references; `InstrumentVersionObserver` allowing only `is_default` changes after publish. Register both in the module service provider.
- [x] 2.6 Factories for `InstrumentVersion` (draft state) and minimal content factories for observer/verify tests — all 9 versioned models got a factory (not just the minimal set) so the verify-command fixture tests could build purpose-built broken drafts cheaply.
- [x] 2.7 Feature tests: immutability matrix (update/delete/restore on published content throws; draft is writable; `is_default` flip on published version allowed, second default rejected by the partial unique). — `Modules/Instrument/tests/Feature/ImmutabilityTest.php` (7 tests, also covers create-under-published and restore-under-published).

## Phase 3 — Content data + seeder

- [x] 3.1 Copied the raw extracts VERBATIM — from `openspec/changes/edi-instrument-catalog/_source/` (this session's job tmp dir at `/Users/rodriguez/.claude/jobs/1d2c6bff/tmp/instrument/` no longer existed; the user pointed to the already-copied, non-ephemeral location) — into `backend/Modules/Instrument/database/seeders/csv/_source/` (6 files) + `README.md` with provenance (dump origin, extraction notes, known anomalies: sentinel ranges incl. the `99912` typo in `GRUPO 11A`, `CO-R` codes, 4 green-first ALE15 items).
- [x] 3.2 Transformed `_source` → `csv/edi-legacy-2013/{age_group,screening_item,risk_factor,head_circumference_reference}.csv` per the design §6 headers via a one-shot Python ETL script (not committed, per D20 "throwaway script"): discarded sentinel ranges, mapped `clasificacion_alternativa` → `alternate_group_code`, area parsed from the `eje` column's prefix with `CO-R → CO` normalization (D9) — `eje` turned out to double as the verbatim `code` column, no separate area column existed in the source; positions → keys, colors → green/yellow/red keys, `H`/`M` → `male`/`female`, cm → integer mm (×10, exact), kept `legacy_id`, questions verbatim (incl. embedded `<br>` and literal newlines). Copied the four files into `csv/edi-2022-2ed/` (identical bank, D20).
- [x] 3.3 Generated `csv/edi-legacy-2013/area_scoring_rule.csv` programmatically from `area_item_counts.csv` (the matrix) + the design §5.1 threshold table — 151 rows exactly (92 official + 59 alternate), verified by count. **Applied design.md §13 corrections**: G15's 7 rows tagged `source=oracle_confirmed` (not `extrapolated`); all 59 alternate rows tagged `source=oracle_confirmed` with `red_threshold=1` uniformly (not the tiered 1/2 split D13 proposed, and not `legacy_js_commented`) — the oracle showed the commented legacy block never ran. Copied identically to `csv/edi-2022-2ed/`.
- [x] 3.4 Generated `csv/edi-legacy-2013/global_scoring_rule.csv` programmatically (59 rows exactly: G1=3, G2–G4=4 each, G5–G15=4 each; one-line JSON conditions; G5–G12 use `["SO"]`, G13–G15 `["SO","CO"]`; `alert_signs`/`risk_factors` appear ONLY in G1–G4, D15 bug preserved) and `global_result_message.csv` (4 rows, texts per design §5.3). **§13 correction applied**: G15's 4 global rules tagged `source=oracle_confirmed` (not `extrapolated`) — same shape as G13/G14, confirmed by the same 228/228 oracle replay (this extension from area-rule to global-rule scope was my own judgment call, documented in the row `notes`, since §13 speaks explicitly only to the area-rule level). Copied both to `csv/edi-2022-2ed/`.
- [x] 3.5 `database/seeders/InstrumentSeeder.php` + `app/Services/InstrumentCsvLoader.php`: `VERSIONS` const (keys, names, `age_calculation_mode`, `is_default`, `source_note`), draft→load→verify→hash→publish flow, published-version skip/abort semantics (D21), UUIDv5 ids via 2.4, catalog FK resolution by key (`fopen`/`fgetcsv`, not `file()+str_getcsv`, because several question fields embed literal newlines inside quoted CSV cells). Registered in `backend/database/seeders/DatabaseSeeder.php` after `CatalogSeeder`.
- [x] 3.6 `app/Services/InstrumentContentHasher.php`: canonical serialization + SHA-256 per design §7. Ids/`legacy_id` excluded; FK columns re-resolved to their NATURAL key (code/key string) so the hash is content-only. Deviation, documented in-code: `notes` (free-text provenance commentary) is also excluded from the hash — only `source` (a meaningful tag) is included — so editing a documentation comment can never force a false-positive hash mismatch on published content.
- [x] 3.7 Feature tests (seeder): exact counts per version (29/451/902/7/118/151/59/4 — verified against both versions), both published, only `edi-2022-2ed` default; UUIDv5 determinism across `migrate:fresh` + re-seed; idempotent re-seed no-op (row counts AND hashes unchanged); tampered CSV (raw-SQL mutation of a published row) → abort with unchanged row count and unchanged stored hash; `G12A` CO normalization + RED/YELLOW-only invariant; `G1A` `MG1.2` reuses the code with alternate (yellow) coloring + `not_applicable` position; ALE15 green-first order preserved. — `Modules/Instrument/tests/Feature/InstrumentSeederTest.php` (9 tests).

## Phase 4 — Verification command

- [x] 4.1 `app/Services/InstrumentVerificationService.php`: checks V1–V10 from design §7 returning a structured failure list; grammar validator for §5.4 (closed operators, SET vocabulary, color keys, per-group `color_in` area existence — validated against the group's OWN item areas, not a global area list — single-operator objects).
- [x] 4.2 `app/Console/InstrumentVerifyCommand.php` (`instrument:verify {version}`): prints every failure, exit 1 on any; registered in the module provider.
- [x] 4.3 Feature tests: command exits 0 for both seeded versions (this is the CI gate); each V-check fails on a purpose-built draft fixture (range gap, missing option, wrong `item_count`, missing catch-all, unknown operator `xor`, message color mismatch, alternate-coverage hole, percentile month hole, raw-SQL tamper → V10 hash mismatch). — `Modules/Instrument/tests/Feature/InstrumentVerifyCommandTest.php` (11 tests). Fixtures are minimal (isolate one defect each) and assert their check's message is PRESENT among the failures, not that it is the ONLY failure — a full 451-item valid baseline was not rebuilt per fixture.

## Phase 5 — Registration + read-only API

- [x] 5.1 `backend/database/seeders/csv/modules.csv`: row `ed000002-0000-4000-8000-000000000001,Instrumento EDI,Core`.
- [x] 5.2 `backend/database/seeders/csv/permissions.csv`: 6 rows for `instrument` referencing that module id, existing description style.
- [x] 5.3 `backend/database/seeders/csv/module_system.csv`: row → Core system `af21b731-78d5-4ca1-9bc0-4b4e4bdeadf2`, sort_order 30, group_label `EDI`.
- [x] 5.4 `backend/database/seeders/SystemTableSeeder.php`: `'Instrumento EDI' => 'instrument'` (permission entities), `=> '/instrument'` (routes), icon `'Instrumento EDI' => 'IconClipboardHeart'` — confirmed the icon key exists in `@tabler/icons-react` and added it to `frontend/src/shared/utils/iconMap.ts`.
- [x] 5.5 `backend/database/seeders/RolePermissionTableSeeder.php`: appended the 6 `{verb}.instrument` names (incl. `read`) to `$permissionsOnlySuperAdmin`, with a comment stating instrument entities MUST NOT enter `$mapperSelects`/`CatalogType` (D22).
- [x] 5.6 Read-only controllers per design §8 (all `$keyForPermission = 'instrument'`, `includes()` for `options`/`message`/etc., filterable by version/group) + `routes/api.php` with `Orion::resource(...)->only(['index','show'])` under `['auth:sanctum','initializeTenancy']` + `throttle:api`. Added a `sex()` relation + `includes(['sex'])` on `HeadCircumferenceReferenceController` (not in the original design list) so the frontend never has to hardcode ChildRecord's sex UUID constants.
- [x] 5.7 Ran `php artisan db:seed --class=ResetPermissionTableSeeder` (equivalent seeder chain) on dev; confirmed `route:list` shows only index/show for all 8 resources; confirmed via feature test that `GET /api/menu` shows Instrumento EDI under Core for super admin and never for a tenant admin.
- [x] 5.8 Feature tests: super admin index/show 200 with no `legacy_id` substring anywhere in the payload; tenant `administrator` 403 on every instrument route (all 8); POST routes absent (404/405) on `screening_item` and `instrument_version`; menu composition per REQ-INS-014. — `Modules/Instrument/tests/Feature/InstrumentApiPermissionTest.php` (4 tests).

## Phase 6 — Frontend inspector

- [x] 6.1 `frontend/src/modules/instrument/schema.ts`: interfaces for all 8 content/registry resources plus `IInstrument`/`IResultColor`/`IDevelopmentArea`/`IApplicationPosition`/`ISex` (support types the pages consume via `include`), and the closed-grammar `ICondition` union type. Zero `any`, no Zod form schemas.
- [x] 6.2 `frontend/src/modules/instrument/services/InstrumentResource.ts` (plain `createCrudApi` list/show wrappers, no mutation ever called) + `hooks/useInstrumentQueries.ts` (the actual TanStack Query hooks). **Deviation, documented in-code**: Orion's query-filter engine (`filters[]`) is wired ONLY into its `/search` action (verified by reading `HandlesStandardOperations`/`QueryBuilder` in `vendor/tailflow/laravel-orion`); since this module registers only `index`/`show` per design D23, GET-index filtering is unavailable server-side. `fetchAllPages` walks the (small, bounded — max 451/version, 902 total) result set page-by-page (Orion's max `limit` is 100) and every hook filters client-side by the FK it needs. This is instrument CONTENT for an admin-only inspector, lazily fetched per tab, not a catalog-dropdown load.
- [x] 6.3 `InstrumentVersionListPage.tsx`: versions table (key, nombre, modo de edad, badge "Predeterminada", fecha de publicación, hash prefix) with `displayFormatDate()`.
- [x] 6.4 `InstrumentVersionDetailPage.tsx` + components (`GroupsTab`/`GroupItemsAccordion`, `AreaRulesTab`, `GlobalRulesTab`, `RiskFactorsTab`, `HeadCircumferenceTab`, `conditionPrettyPrinter.ts`, `resultColorBadge.ts`): tabs Grupos (Radix `Collapsible`-based accordion — no dedicated Accordion primitive exists in `components/ui/`; items with option color badges, `<br>` rendered as line breaks per Q6), Reglas por eje, Reglas globales (sequence, pretty-printed condition, message), Factores de riesgo (auto-derivation badge), Perímetro cefálico (mm shown as cm with one decimal). Lazy per-tab fetching (each tab's hook is `enabled` only while mounted/visible via the version id).
- [x] 6.5 `frontend/src/App.tsx`: `PermissionRoute permissionEntity="instrument"` with routes `/instrument` and `/instrument/:id`.
- [x] 6.6 Vitest: `conditionPrettyPrinter.test.ts` (7 tests, all 6 operators + catch-all) and `InstrumentVersionDetailPage.test.tsx` (2 tests, mocks every query hook — smoke render + loading state).

## Phase 7 — Verification & closure

- [x] 7.1 Backend: `./vendor/bin/pint` clean (0 fixes needed) on every new/touched file; full suite `DB_HOST=127.0.0.1 DB_PORT=5451 DB_USERNAME=rodriguez DB_PASSWORD=password php artisan test --parallel --processes=10` → 609 passed (575 baseline + 34 new Instrument tests), includes the `instrument:verify` CI gate from 4.3.
- [x] 7.2 Frontend: `pnpm exec tsc -p tsconfig.app.json --noEmit` clean; `pnpm exec vitest run` → 221 passed (212 baseline + 9 new); `pnpm build` green; grep confirms zero TypeScript `any` in `frontend/src/modules/instrument/`.
- [x] 7.3 `migrate:fresh --seed` from clean: both versions published and hashed; `instrument:verify` exits 0 for both; re-run `db:seed --class=InstrumentSeeder` is a no-op (silent, no errors).
- [x] 7.4 Updated `.project-context/modules.md` (Instrument: not-yet-built → built, with the 13-table list). Handoff to `sdd-verify` is next; `sdd-archive` follows after that.
