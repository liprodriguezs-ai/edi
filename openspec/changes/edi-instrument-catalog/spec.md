# Spec: EDI Instrument Catalog

Change: `edi-instrument-catalog`
Capabilities: `instrument-catalog` (new)

> RFC 2119 keywords (MUST, MUST NOT, SHALL, SHOULD, MAY) are normative.
> All tables in this spec are CENTRAL (`BaseModel::columns($table, false)`, no `BelongsToTenant`).
> "Version" = a row of `instrument_version`. "Content row" = any row of a versioned content table.
> On archive, REQ-INS-* merge into `openspec/specs/instrument-catalog/spec.md`.

---

## ADDED Requirements — capability `instrument-catalog`

### REQ-INS-001 — Instrument and version registry

The system SHALL store instruments (`instrument`, key `edi`) and their editions (`instrument_version`). A version carries `key` (unique per instrument), `name`, `age_calculation_mode` (`thirty_day_month` | `calendar`), `is_default`, `published_at`, `content_hash`, `source_note`. Exactly one non-deleted version per instrument MAY be default, enforced by a partial unique index; a version MUST be published before it can be default.

#### Scenario: seeded versions exist with the correct modes
- WHEN `migrate:fresh --seed` completes
- THEN `instrument_version` contains `edi-legacy-2013` with `age_calculation_mode = thirty_day_month` and `edi-2022-2ed` with `age_calculation_mode = calendar`
- AND only `edi-2022-2ed` has `is_default = true`, and both have `published_at` set.

#### Scenario: two defaults are impossible
- GIVEN `edi-2022-2ed` is default
- WHEN code attempts to mark `edi-legacy-2013` default without demoting the current one in the same transaction
- THEN the partial unique index rejects the write.

### REQ-INS-002 — Published content is immutable

Content of a published version MUST NOT change: any create/update/delete/restore of a content row whose version has `published_at` set MUST be rejected by a model observer with a domain exception. A new edition is a NEW version; `UPDATE` of published content is never a valid path. On `instrument_version` itself, only `is_default` MAY change after publish.

#### Scenario: editing a published item throws
- GIVEN version `edi-2022-2ed` is published
- WHEN code updates the `question` of one of its `screening_item` rows
- THEN an `ImmutableInstrumentVersionException` (or equivalent) is thrown and the row is unchanged.

#### Scenario: draft content is editable
- GIVEN a version with `published_at = NULL`
- WHEN its content is created or modified
- THEN the write succeeds (the seeder builds drafts, verifies, then publishes).

#### Scenario: deleting published content throws
- WHEN code soft-deletes an `age_group` of a published version
- THEN the observer rejects it.

### REQ-INS-003 — Content hash integrity

At publish time the system MUST compute `content_hash` = SHA-256 over the canonical serialization of the version's full content (deterministic table order, rows sorted by natural key, fixed field order, integers and strings only, `JSON_UNESCAPED_UNICODE`, no whitespace). Recomputing the hash of a published version MUST reproduce the stored value; a mismatch is a verification failure, never silently accepted.

#### Scenario: hash is reproducible
- GIVEN a published version
- WHEN the hash is recomputed from the database
- THEN it equals `instrument_version.content_hash`.

#### Scenario: tampering is detected
- GIVEN a published version
- WHEN a `screening_item_option.result_color_id` is mutated via raw SQL (bypassing the observer)
- THEN `instrument:verify` reports a content-hash mismatch and exits non-zero.

### REQ-INS-004 — Deterministic identity and traceability

Every content row's `id` MUST be a UUIDv5 derived from a fixed project namespace and the name `{version_key}/{table}/{natural_key}` (natural keys per design §4). Seeding the same CSVs on any environment MUST therefore produce identical ids. Rows extracted from the legacy dump carry `legacy_id` (nullable integer) for traceability; `legacy_id` MUST be hidden from all API responses (`$hidden`).

#### Scenario: ids are stable across re-seeds
- WHEN the database is dropped, re-migrated, and re-seeded
- THEN the id of item `MG1.2` of group `G1` of `edi-legacy-2013` is byte-identical to the previous seed.

#### Scenario: legacy_id never leaves the API
- WHEN a super admin fetches any instrument resource with any `include`
- THEN no response payload contains a `legacy_id` field.

### REQ-INS-005 — Age group structure

Each version SHALL contain 15 official age groups (`G1`–`G15`, `is_alternate = false`) whose `[min_age_days, max_age_days]` ranges are exactly: G1 30–59, G2 60–89, G3 90–119, G4 120–149, G5 150–209, G6 210–299, G7 300–389, G8 390–479, G9 480–569, G10 570–749, G11 750–929, G12 930–1109, G13 1110–1469, G14 1470–1799, G15 1800–2159 — contiguous, non-overlapping, covering 30–2159. It SHALL contain 14 alternate groups (`G1A`–`G14A`, `is_alternate = true`) with NULL age ranges: the legacy sentinel ranges (`9992`…`9915`, including the `99912` typo in 11A) MUST be discarded at seed time, never stored. Every official group except `G1` MUST point via `alternate_age_group_id` to its alternate (G2→G1A … G15→G14A); `G1` has none; every alternate is referenced by exactly one official group.

#### Scenario: day ranges partition the screening window
- WHEN official groups of a seeded version are ordered by `min_age_days`
- THEN each group's `min_age_days` equals the previous group's `max_age_days + 1`, the first is 30 and the last max is 2159.

#### Scenario: sentinel garbage is not stored
- WHEN alternate groups are inspected
- THEN all have `min_age_days IS NULL AND max_age_days IS NULL` and no row anywhere stores a value ≥ 9000 days.

#### Scenario: alternate pointers
- THEN `G13.alternate_age_group_id` resolves to `G12A`, `G2` to `G1A`, `G15` to `G14A`, and `G1.alternate_age_group_id IS NULL`.

### REQ-INS-006 — Item bank shape

Each seeded version SHALL contain exactly 451 `screening_item` rows, each linked to a group, a development area, and an application position, with `code` stored VERBATIM from the source (e.g. `MG1.2`, `LE10.3`, `ALE1.4`, `CO-R 1` — codes are opaque; nothing may parse them to derive group or area). Item codes are unique per group, NOT globally (alternate groups reuse the official codes with different options). Every item MUST have exactly 2 options (`screening_item_option`) with presentation order preserved from the source (`sort_order` 1|2) and two DISTINCT result colors. Option colors MUST NOT be assumed to include green (the 3 `CO-R` items produce only RED/YELLOW) nor to start with the "bad" option (4 items — `ALE15.1`–`ALE15.4` — have green first).

#### Scenario: the CO-R anomaly is normalized to area CO with verbatim code
- WHEN group `G12A` of a seeded version is inspected
- THEN it has exactly 3 items whose `development_area` is `CO` and whose codes are `CO-R 1`, `CO-R 2`, `CO-R 3`
- AND each offers `NO → red` and `SI → yellow` (no green option).

#### Scenario: alternate items reuse codes with alternate colors
- GIVEN official `G1` item `MG1.2` has options `NO → red`, `SI → green`
- WHEN `G1A` is inspected
- THEN it also contains an item coded `MG1.2` whose options are `NO → red`, `SI → yellow`, and whose position is `not_applicable`.

#### Scenario: option invariants hold bank-wide
- WHEN all items of both versions are checked
- THEN every item has exactly 2 options and no item has 2 options with the same `result_color_id`.

### REQ-INS-007 — Item distribution facts (matrix)

The seeded bank MUST reproduce the source matrix per (group, area): development axes MG/MF/LE/SO have 2 items in official groups 1–7 and 3 in groups 8–15; CO exists ONLY in official groups 13–15 (3 items each); EN has 3 items in every official group except G15 (2); ALA is absent from G1 and has 1–4 items elsewhere; ALE varies between 2 and 8 items (e.g. 8 in G14, 4 in G15). Alternate groups contain ONLY development axes: MG/MF/LE/SO in G1A–G11A, plus CO in G12A–G14A (2 items per axis in G1A–G7A, 3 in G8A–G14A).

#### Scenario: CO boundaries
- THEN official groups G8–G12 have zero CO items, G13–G15 have exactly 3 each, and among alternates only G12A, G13A, G14A have CO items.

#### Scenario: EN exception in G15
- THEN `G15` has exactly 2 EN items while `G1`–`G14` have exactly 3 each (the missing third is the head-circumference item — see REQ-INS-011).

#### Scenario: alternates carry no EN/ALA/ALE
- WHEN any alternate group is inspected
- THEN it contains no items in areas EN, ALA, or ALE.

### REQ-INS-008 — Area scoring rules as data

For every (group, area) pair where the area is semaphore-scored (`development_area.scoring_kind = semaphore`: MG, MF, LE, SO, CO, EN, ALA) and items exist, the version SHALL contain exactly one `area_scoring_rule` row with `item_count` equal to the real item count, plus `red_threshold` (NOT NULL), `green_threshold` (nullable — NULL means "no green branch"), `allows_yellow`, and `source` (`legacy_js` | `legacy_js_commented` | `extrapolated` | `manual`). ALE is a counter (`scoring_kind = counter`): it MUST have NO rule rows and is never semaphore-aggregated. The evaluation contract these rows encode (implemented by the future engine, normative here as data semantics) is: `reds >= red_threshold → RED; else if green_threshold IS NOT NULL and greens >= green_threshold → GREEN; else if allows_yellow → YELLOW; else GREEN`.

#### Scenario: legacy thresholds per stratum
- WHEN `edi-legacy-2013` area rules are inspected
- THEN G1 dev axes have (red 1, green 0, yellow false); G2–G8 dev axes have (red 1, green 2, yellow true); G9–G14 dev axes and G13–G14 CO have (red 2, green 2, yellow true); EN and ALA everywhere have (red 1, green 0, yellow false).

#### Scenario: alternate rules can never yield green
- WHEN rules of alternate groups are inspected
- THEN `green_threshold IS NULL` and `allows_yellow = true` on all of them, with `red_threshold = 1` for G1A–G6A and `2` for G7A–G14A, all tagged `source = legacy_js_commented`.

#### Scenario: rule coverage is exact
- THEN each seeded version has exactly 151 area rules (92 for official groups, 59 for alternates), every one matching an existing (group, area) item set with the exact count, and no rule exists for a pair without items nor for ALE.

### REQ-INS-009 — Global scoring rules as data

Each OFFICIAL group of a version SHALL have an ordered list of `global_scoring_rule` rows (`sequence` unique per group) whose `condition` is jsonb in a CLOSED grammar of exactly 6 operators — `all` (AND, empty = always true), `any` (OR), `not`, `count {of, gte|eq|lte}`, `sum {of[], gte}`, `color_in {areas[], is}` — where `of` draws only from `red_areas`, `yellow_areas`, `green_areas`, `alert_signs`, `risk_factors`, and `color_in.areas` lists area codes with items in that group. Evaluation is first-match-wins by ascending sequence; the LAST rule of every group MUST be the catch-all `{"all": []}`. Each rule points to a `result_color` and a `global_result_message` of the same version whose color MUST match the rule's color. Alternate groups have NO global rules. The seeded rules MUST transcribe the legacy `funcionesResultadosGlobal` faithfully, including its bug: `alert_signs`/`risk_factors` sums appear ONLY in groups 1–4; groups 5–15 ignore them.

#### Scenario: group 2 red rule encodes the legacy escalation
- WHEN the sequence-10 rule of `G2` in `edi-legacy-2013` is read
- THEN its condition is `any` of: `count(red_areas) >= 1`; `count(yellow_areas) >= 2`; `all[count(yellow_areas) >= 1, sum(alert_signs, risk_factors) >= 1]`, and its color is red.

#### Scenario: groups 5–14 ignore alert signs and risk factors
- WHEN all conditions of groups `G5`–`G14` in `edi-legacy-2013` are scanned
- THEN none references `alert_signs` or `risk_factors`.

#### Scenario: catch-all closes every group
- WHEN the highest-sequence rule of each official group is read
- THEN its condition is exactly `{"all": []}` and its color is green.

#### Scenario: yellow messages split by area family
- THEN yellow rules driven by MG/MF/LE map to message `yellow_development` and those driven by SO/CO map to `yellow_social`, and groups without CO items (G5–G12) reference `["SO"]`, never `["SO","CO"]`.

### REQ-INS-010 — Biological risk factors

Each version SHALL contain exactly 7 `risk_factor` rows (codes `FRB1`–`FRB7`, question text verbatim from the source). Rows FRB3, FRB4, FRB7 MUST carry `auto_derivation_key` values `gestational_age_lt_34_weeks`, `birth_weight_lte_1500_g`, `mother_age_lt_16_years` respectively; the other four have NULL. The keys exist so the future engine can auto-derive them from `birth_record` — this change stores metadata only.

#### Scenario: auto-derivable factors are marked
- WHEN the risk factors of `edi-2022-2ed` are listed
- THEN exactly 3 have a non-null `auto_derivation_key`, matching FRB3/FRB4/FRB7.

### REQ-INS-011 — Head-circumference percentile reference

Each version SHALL contain the percentile band table keyed by (`sex_id`, `age_months`) with `min_circumference_mm` and `max_circumference_mm` stored as INTEGER MILLIMETERS (source cm × 10, exact — e.g. 35.5 cm → 355; decimals MUST NOT be stored). `sex_id` references the central `sex` catalog (legacy `H` → male, `M` → female; app-level reference, no DB FK). The seeded coverage is months 1–59 per sex (118 rows) — G15 (60–71 months) has no reference, consistent with its missing EN head-circumference item. Coverage per sex MUST be contiguous (no month holes) and every row MUST satisfy `min < max`.

#### Scenario: exact integer conversion
- GIVEN the legacy row (month 1, H, 35.5–39.0 cm)
- WHEN the seeded table is read
- THEN the male month-1 row stores `min = 355`, `max = 390`.

#### Scenario: coverage window
- THEN each sex has exactly one row per month 1..59 and none outside that range.

### REQ-INS-012 — Two seeded versions, idempotent and drift-proof

`InstrumentSeeder` SHALL seed both versions from per-version CSV directories committed to the repo (diffable, review-friendly), then verify and publish each. Re-running the seeder against an already-published version MUST recompute the hash and (a) no-op when it matches, (b) ABORT the seed with a loud error when it differs — it MUST NOT update published content. The raw legacy extracts are committed under a `_source/` directory for provenance.

#### Scenario: re-seed is a no-op
- GIVEN both versions seeded and published
- WHEN `db:seed` runs again
- THEN row counts and every content id and `content_hash` are unchanged.

#### Scenario: divergent CSV aborts
- GIVEN a published `edi-legacy-2013`
- WHEN the seeder runs with a modified item CSV for that version
- THEN it throws/aborts naming the version and hash mismatch, and the database is unchanged.

### REQ-INS-013 — Verification command

`php artisan instrument:verify {version-key}` SHALL validate a version and exit non-zero listing every failure: (a) official day ranges contiguous, gap-free, covering 30–2159; (b) every item has exactly 2 options with distinct colors; (c) area-rule coverage and `item_count` exactness per REQ-INS-008; (d) every official group ends with the catch-all global rule and every condition validates against the closed grammar and vocabulary (unknown operator/keys/area codes are failures); (e) rule→message color coherence and same-version references; (f) alternate pointers per REQ-INS-005 and alternate area coverage: every development axis with items in an official group has items in its alternate; (g) percentile invariants per REQ-INS-011; (h) recomputed `content_hash` equals stored for published versions; (i) every content row's `instrument_version_id` matches its parent chain. The test suite MUST run the command for both seeded versions so CI fails on any regression.

#### Scenario: green on healthy seed
- WHEN `instrument:verify edi-legacy-2013` and `instrument:verify edi-2022-2ed` run after seeding
- THEN both exit 0.

#### Scenario: broken invariant is caught
- GIVEN a draft version fixture with a G3 whose `min_age_days` is 91 (gap of one day)
- WHEN `instrument:verify` runs against it
- THEN it exits non-zero and names the range gap.

#### Scenario: unknown grammar operator is rejected
- GIVEN a draft rule with condition `{"xor": []}`
- THEN verification fails citing the invalid operator.

### REQ-INS-014 — Access control and platform registration

The instrument API SHALL be read-only (routes expose `index`/`show` only) and gated by the `instrument` permission entity, whose 6 permissions are seeded and added to `$permissionsOnlySuperAdmin` — tenant roles never receive them. The module registers under the **Core** system (`modules.csv` row `Instrumento EDI`, `permissions.csv` 6 rows, `module_system.csv` → Core, `SystemTableSeeder` maps incl. icon present in `iconMap.ts`, `modules_statuses.json`). Instrument content MUST NOT be exposed through `POST /selects`: no `$mapperSelects` or `CatalogType` entry may exist for any instrument entity.

#### Scenario: tenant admin is denied
- GIVEN an `administrator` token of a tenant
- WHEN it GETs `/api/instrument_version`
- THEN the response is 403; AND the same request as super admin returns both versions.

#### Scenario: no write routes exist
- WHEN a super admin POSTs `/api/screening_item`
- THEN the response is 404/405 (route not registered), not 201.

#### Scenario: menu placement
- WHEN a super admin GETs `/api/menu`
- THEN `Instrumento EDI` appears under Core; AND it never appears for tenant roles.

### REQ-INS-015 — Read-only inspector frontend

The React module `frontend/src/modules/instrument/` SHALL provide a read-only inspector for super admin: version list (key, mode, default badge, published date, hash prefix) and version detail (groups with items and option colors, area rules, global rules with pretty-printed conditions and messages, risk factors, percentile table). UI text in Mexican Spanish, zero `any`, no mutation calls, no eager catalog loading, routes guarded by `PermissionRoute permissionEntity="instrument"`.

#### Scenario: inspector renders a version
- WHEN a super admin opens `/instrument` and selects `edi-2022-2ed`
- THEN groups, rules, and the percentile table render from the API without any write request being issued.

#### Scenario: colors are visible facts
- WHEN item `MG1.2` of `G1` is displayed
- THEN each option shows its resulting color (NO → rojo, SI → verde) as rendered semaphore badges, not raw UUIDs.
