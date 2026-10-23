# Design: EDI Instrument Catalog

Change: `edi-instrument-catalog`

## 1. Context

One nwidart module, `Instrument`: the rulebook every screening will point to. All tables are CENTRAL (`BaseModel::columns($table, false)`, no `BelongsToTenant`) — the instrument is platform content, read-only for tenant roles. House patterns cloned from the surviving modules and `edi-clinic-and-child-registry`: no DB-level FKs (uuid + index + app-level integrity), partial unique indexes via `DB::statement(... WHERE deleted_at IS NULL)`, fixed-UUID catalog constants seeded by `CatalogSeeder::upsertCatalog` (idempotent `upsert` on id), table comment inside the closure + `->comment()` on every own column.

Sources of truth used here:
- Structure/content: clean extracts of the legacy production dump (29 groups, 451 items, 7 FRB, 6 positions, 118 percentile rows, group×area matrix).
- Scoring semantics: `edi-backend/module/FuaEdi/view/fua-edi/fua-edi/index.phtml` — `funcionesResultados` (L614–729), `funcionesResultadosGlobal` (L730–833), counters `totalElementosSIFRB`/`SIALE` (L1079–1100), message blocks in `resultadoGlobal.phtml`.

## 2. Decisions

| # | Decision | Rationale |
|---|---|---|
| D1 | Single module `Instrument`, 13 tables, all central | The rulebook is platform content; per `.project-context/modules.md`. No tenant may edit a comma of it |
| D2 | Three support catalogs beyond the 10 target tables: `result_color`, `development_area`, `application_position` | Hard rule 4: code branches on colors and areas → catalog tables with fixed UUIDs, never PHP enums. Positions come from `cat_edi_posicion` and carry the Spanish UI label. `result_color` will be reused by Screening (`screening.result_color_id`) |
| D3 | These 3 catalogs are NOT versioned (no `instrument_version_id`) | They are vocabulary, not edition content: MG means "motriz gruesa" in every edition. Version-varying behavior lives in the rule tables |
| D4 | Every versioned content row carries `instrument_version_id`, even when reachable via parent (item → group) | Task hard rule; makes the immutability observer and verify O(1) per row instead of walking chains; V-check (i) guards the redundancy |
| D5 | Immutability = observer `InstrumentContentObserver` on all 10 content models: `saving`/`deleting`/`restoring` throw if the version is published. On `instrument_version` only `is_default` may change after publish | New edition = new version. API is read-only anyway; the observer defends against seeder/tinker/console mistakes |
| D6 | Deterministic UUIDv5: namespace `ed1c0000-0000-4000-8000-000000000001`, name `{version_key}/{table}/{natural_key}` | Same id in dev/CI/prod; CSVs diffable; re-seed idempotent by construction. `Ramsey\Uuid::uuid5` |
| D7 | `legacy_id` (nullable int) on extracted rows, `$hidden` in models | Traceability to the dump without leaking legacy internals through the API |
| D8 | Alternate groups store NULL age ranges; sentinel values (`9992`…, incl. the `99912` typo) are discarded at transform time | The sentinels are storage garbage, not semantics: alternates are reached ONLY via `alternate_age_group_id`, never by age lookup |
| D9 | `G12A`'s `CO-R n` items: `development_area_id` normalized to CO; `code` kept VERBATIM (`CO-R 1`) | The area is what rules and coverage checks join on; the code is display/traceability. Only occurrence in the bank — asserted by a seeder test |
| D10 | Item `code` is opaque text, unique per (age_group), NOT globally | Alternates reuse official codes (`MG1.2` exists in G1 and G1A with different option colors). Nothing may parse codes to derive group/area — the extract columns carry both explicitly |
| D11 | Option rows preserve source presentation order (`sort_order` 1|2 = `valor1`/`valor2`) and verbatim labels (`SI`/`NO`) | Only 4 of 451 items have green first (`ALE15.1–4`); order is data, not convention. Distinct-color invariant instead of "must include green" — `CO-R` items are RED/YELLOW only |
| D12 | `area_scoring_rule` semantics: `reds >= red_threshold → RED; elif green_threshold NOT NULL and greens >= green_threshold → GREEN; elif allows_yellow → YELLOW; else GREEN`. ALE has NO rules (`development_area.scoring_kind = counter`) | One 4-branch contract encodes every legacy variant (§5.1). `green_threshold NULL` = "no green branch" (alternate sets, which have no green options). ALE/FRB are displayed "en cifras" in the legacy — counters, never semaphores |
| D13 | Alternate area rules seeded from the COMMENTED legacy block (index.phtml L1150–1180), tagged `source = legacy_js_commented`; split by the POINTING official group: G2–G7's alternates (G1A–G6A) red 1, G8–G15's (G7A–G14A) red 2 | The legacy runtime never executed that block (alternate answers did not alter the stored global), but it is the only written-down intent. The commented code pooled all alternate answers; per-area rows are the normalized interpretation. The engine change decides replay semantics — the catalog just records the thresholds |
| D14 | G15 rules (absent from legacy JS — no `funcionesResultados[15]`, no `resultados15.phtml`) are seeded in BOTH versions extrapolating the G9–14 pattern, tagged `source = extrapolated` | 228 production screenings carry G15 results that cannot be left un-modelable. Extrapolation: dev+CO (red 2, green 2, yellow), EN 2 items (red 1, green 0, no yellow), ALA (red 1), global = G5–14 shape. Open decision Q1 confirms against the manual |
| D15 | The ALE+FRB scope bug (sums only in G1–G4) is reproduced in BOTH versions | `edi-legacy-2013` is the oracle — bug-compatible by definition. For `edi-2022-2ed`, inventing clinical thresholds without the 2nd-ed manual is worse than inheriting the omission; open decision Q2 |
| D16 | Global-rule grammar: closed, 6 operators, jsonb; first-match-wins by `sequence`; mandatory trailing catch-all `{"all": []}` | Rules stay data (invariant 3); the engine becomes a ~50-line interpreter; verify statically validates every condition. `not` is unused by the seeds but belongs to the closed grammar |
| D17 | Two yellow messages (`yellow_development`, `yellow_social`) even though the legacy TEXTS are identical | The legacy DOM distinguishes `#resultadoAmarillo` vs `#resultadoAmarillo2` (driven by MG/MF/LE vs SO/CO) and the manual differentiates the referral emphasis. Message rows: key, color, headline, `referral_items` jsonb (the bullets) |
| D18 | In G1 the yellow global outcome maps to `yellow_social` directly | G1 areas can never be YELLOW (no-yellow rules), so the legacy `MG/MF/LE == AMARILLO` branch is dead code; transcribing it would be false fidelity |
| D19 | `head_circumference_reference` in integer mm (cm × 10, exact), keyed (`sex_id`, `age_months`); references ChildRecord's central `sex` catalog (H→male, M→female), app-level, no DB FK | Hard rule 7 (the legacy compared floats); the engine's lookup key is month+sex. Coverage 1–59 months per sex — G15 has none (matches its missing EN `.3` item); open decision Q3 |
| D20 | Both versions seed from their own committed CSV directory; raw extracts committed once under `_source/` for provenance | Self-contained frozen content per version; the transform is one-shot, the CSVs are the reviewed artifact. Job tmp dir is ephemeral — copying it into the repo is Phase 3 step 1 |
| D21 | Publish flow inside the seeder: create draft → load CSVs → run verify checks in-process → compute hash → publish. Re-seed of a published version: recompute hash; equal → skip; different → ABORT | Idempotent and drift-proof. Nothing can "re-seed over" a published version |
| D22 | Registration under **Core** system (`af21b731-78d5-4ca1-9bc0-4b4e4bdeadf2`); permissions in `$permissionsOnlySuperAdmin`; NO `$mapperSelects`/`CatalogType` entries | Inspector is super-admin-only. Tenant-facing screening flows will be served by the Screening module's own endpoints, never by raw instrument browsing or `POST /selects` |
| D23 | API read-only: Orion routes registered with `only(['index','show'])`; all controllers share `$keyForPermission = 'instrument'` | Content enters via seeder exclusively. One permission family gates the whole surface (precedent: BirthRecord → `child`) |

## 3. Data model (definitive)

Every table: `$table->comment('...')` first line inside the closure, then `BaseModel::columns($table, false)`; every own column `->comment()`. FK-style columns are `uuid(...)->index()`; no DB FKs. Partial uniques via `DB::statement`.

### 3.1 Support catalogs (fixed UUIDs, `CatalogSeeder`)

| Table | Rows | Columns (beyond base) | Constants |
|---|---|---|---|
| `result_color` | 3 | `key` string unique (green/yellow/red), `name` (Verde/Amarillo/Rojo), `sort_order` smallint | `ResultColor::GREEN = ed200001-…0001`, `YELLOW = …0002`, `RED = …0003` |
| `development_area` | 8 | `code` string unique (MG/MF/LE/SO/CO/EN/ALA/ALE), `name` (Motriz gruesa, Motriz fina, Lenguaje, Social, Conocimiento, Exploración Neurológica, Señales de Alarma, Señales de Alerta), `scoring_kind` string ('semaphore'\|'counter'), `is_development_axis` bool (true: MG/MF/LE/SO/CO), `sort_order` | `DevelopmentArea::MG = ed200002-…0001` … `ALE = …0008` |
| `application_position` | 6 | `key` string unique (supine/prone/sitting/on_floor/standing/not_applicable), `name` (Supina/Prona/Sentado/En piso/De pie/No aplica), `legacy_id` smallint | `ApplicationPosition::SUPINE = ed200003-…0001` … `NOT_APPLICABLE = …0006` |

`scoring_kind`: semaphore for MG/MF/LE/SO/CO/EN/ALA; counter for ALE. FRB is not an area (own table).

### 3.2 Registry

**`instrument`** — 'Screening instrument (test) whose content is versioned; EDI is the first'
`key` string unique ('edi'), `name` string, `description` nullable. Constant `Instrument::EDI = ed200004-…0001`.

**`instrument_version`** — 'Immutable edition of an instrument; all content hangs from it; published versions never change'

| Column | Type | Notes |
|---|---|---|
| `instrument_id` | uuid, index | FK to instrument (no DB FK) |
| `key` | string | 'edi-legacy-2013' / 'edi-2022-2ed'; unique per instrument (partial) |
| `name` | string | 'EDI legado 2013 (bug-compatible)' / 'EDI 2ª edición (2022)' |
| `age_calculation_mode` | string | 'thirty_day_month' (legacy: month = 30 days, year = 360) \| 'calendar' (real calendar). Versioned arithmetic consumed by the engine |
| `is_default` | boolean default false | Version used for NEW screenings; requires published |
| `published_at` | timestamp nullable | NULL = draft (mutable); set once |
| `content_hash` | char(64) nullable | SHA-256 of canonical serialization; set at publish |
| `source_note` | text nullable | Provenance (dump date, transcription notes) |

Constants: `InstrumentVersion::EDI_LEGACY_2013 = ed200005-…0001`, `EDI_2022_2ED = …0002`.
Indexes: `UNIQUE (instrument_id, key) WHERE deleted_at IS NULL`; `UNIQUE (instrument_id) WHERE is_default AND deleted_at IS NULL`.

### 3.3 Versioned content (10 tables; ids = UUIDv5 per §4; all with `instrument_version_id` uuid index + `legacy_id` int nullable hidden where extracted)

**`age_group`** — 'Age group of an instrument version; official groups partition 30–2159 days, alternates are reached only via pointer'

| Column | Type | Notes |
|---|---|---|
| `code` | string(10) | 'G1'…'G15', 'G1A'…'G14A'; unique per version |
| `name` | string | Verbatim 'GRUPO 1'… (UI data) |
| `ordinal` | smallint | 1..29; unique per version; display/sort |
| `is_alternate` | boolean | true for G1A–G14A |
| `min_age_days` / `max_age_days` | smallint nullable | Officials: the 15 exact ranges; alternates NULL (sentinels discarded, D8) |
| `min_age_months` / `max_age_months` | smallint nullable | Display only (1–71); alternates NULL |
| `alternate_age_group_id` | uuid nullable, index | Officials G2–G15 → their alternate; G1 and all alternates NULL |
| `materials_note` | text nullable | Verbatim 'material' text (test kit) |

Indexes: `UNIQUE (instrument_version_id, code) WHERE deleted_at IS NULL`; `UNIQUE (instrument_version_id, ordinal) WHERE deleted_at IS NULL`.

**`screening_item`** — 'Reactivo of a group; code is verbatim and opaque; two options decide a color'

| Column | Type | Notes |
|---|---|---|
| `age_group_id` | uuid, index | Owning group |
| `development_area_id` | uuid, index | Area (CO-R rows normalized to CO, D9) |
| `application_position_id` | uuid, index | Position catalog (legacy posicion 1–6) |
| `code` | string(20) | Verbatim: 'MG1.2', 'ALE15.4', 'CO-R 1'. Opaque (D10) |
| `question` | text | Verbatim question (may contain `<br>` markup from source) |
| `sort_order` | smallint | Order within (group, area) |

Indexes: `UNIQUE (age_group_id, code) WHERE deleted_at IS NULL`; `UNIQUE (age_group_id, development_area_id, sort_order) WHERE deleted_at IS NULL`; index `(instrument_version_id, age_group_id)`.

**`screening_item_option`** — 'One of the two answers of an item and the color it produces'

| Column | Type | Notes |
|---|---|---|
| `screening_item_id` | uuid, index | Owning item |
| `label` | string(10) | Verbatim 'SI' / 'NO' |
| `sort_order` | smallint | 1\|2 = legacy valor1/valor2 presentation order (D11) |
| `result_color_id` | uuid, index | FK to result_color |

Indexes: `UNIQUE (screening_item_id, sort_order) WHERE deleted_at IS NULL`; `UNIQUE (screening_item_id, label) WHERE deleted_at IS NULL`. No `legacy_id` (embedded in the item row at source).

**`risk_factor`** — 'Biological risk factor (FRB) of a version; three are auto-derivable from birth_record'
`code` string ('FRB1'…'FRB7') unique per version (partial), `question` text verbatim, `sort_order` smallint, `auto_derivation_key` string nullable ('gestational_age_lt_34_weeks' FRB3, 'birth_weight_lte_1500_g' FRB4, 'mother_age_lt_16_years' FRB7).

**`head_circumference_reference`** — 'Percentile band (±2 SD) of head circumference in integer mm by sex and age in months'
`sex_id` uuid index (ChildRecord central catalog, D19), `age_months` smallint, `min_circumference_mm` smallint, `max_circumference_mm` smallint.
Index: `UNIQUE (instrument_version_id, sex_id, age_months) WHERE deleted_at IS NULL`.

**`area_scoring_rule`** — 'Semaphore thresholds for one (group, area); the engine evaluates the 4-branch contract of design D12'
`age_group_id` uuid index, `development_area_id` uuid index, `item_count` smallint, `red_threshold` smallint, `green_threshold` smallint nullable, `allows_yellow` boolean, `source` string ('legacy_js'\|'legacy_js_commented'\|'extrapolated'\|'manual'), `notes` string nullable.
Index: `UNIQUE (age_group_id, development_area_id) WHERE deleted_at IS NULL`.

**`global_scoring_rule`** — 'Ordered global rule of an official group; jsonb condition in the closed grammar; first match wins'
`age_group_id` uuid index, `sequence` smallint, `condition` jsonb, `result_color_id` uuid index, `global_result_message_id` uuid index, `source` string, `notes` string nullable.
Index: `UNIQUE (age_group_id, sequence) WHERE deleted_at IS NULL`.

**`global_result_message`** — 'Displayable outcome of a global rule: headline + referral bullets (Spanish UI data)'
`key` string ('red'\|'yellow_development'\|'yellow_social'\|'green'), `result_color_id` uuid index, `headline` string ('Riesgo de retraso en el desarrollo' / 'Rezago en el desarrollo' ×2 / 'Desarrollo normal'), `referral_items` jsonb (array of Spanish strings — legacy bullets from `resultadoGlobal.phtml`), `sort_order` smallint.
Index: `UNIQUE (instrument_version_id, key) WHERE deleted_at IS NULL`.

Counts per seeded version: 29 age groups, 451 items, 902 options, 7 risk factors, 118 percentile rows, 151 area rules (92 official + 59 alternate), 59 global rules, 4 messages.

## 4. Deterministic identity (D6)

- Namespace constant: `InstrumentContentUuid::NAMESPACE = 'ed1c0000-0000-4000-8000-000000000001'`.
- `id = Uuid::uuid5(NAMESPACE, "{version_key}/{table}/{natural_key}")`.
- Natural keys: `age_group` → `code`; `screening_item` → `{group_code}/{code}`; `screening_item_option` → `{group_code}/{item_code}/{label}`; `risk_factor` → `code`; `head_circumference_reference` → `{sex_key}/{age_months}`; `area_scoring_rule` → `{group_code}/{area_code}`; `global_scoring_rule` → `{group_code}/{sequence}`; `global_result_message` → `key`.
- `instrument` and `instrument_version` use handcrafted constants (§3.2) — they are anchors, not bulk content.

## 5. Legacy rule transcription (authoritative)

### 5.1 `funcionesResultados` → `area_scoring_rule`

| Groups | Areas | item_count | red | green | yellow | source |
|---|---|---|---|---|---|---|
| G1 | MG, MF, LE, SO | 2 | 1 | 0 | false | legacy_js (special-cased per axis, no yellow) |
| G1 | EN | 3 | 1 | 0 | false | legacy_js |
| G2–G7 | MG, MF, LE, SO | 2 | 1 | 2 | true | legacy_js (`==2` ≡ `>=2` with 2 items) |
| G8 | MG, MF, LE, SO | 3 | 1 | 2 | true | legacy_js |
| G9–G14 | MG, MF, LE, SO (+CO in G13–14) | 3 | 2 | 2 | true | legacy_js |
| G2–G14 | EN (3), ALA (1–4 per matrix) | n | 1 | 0 | false | legacy_js ('verde-rojo' rows) |
| G15 | MG, MF, LE, SO, CO (3) | 3 | 2 | 2 | true | extrapolated (D14) |
| G15 | EN (2), ALA (3) | n | 1 | 0 | false | extrapolated |
| G1A–G6A | MG, MF, LE, SO | 2 | 1 | NULL | true | legacy_js_commented (D13) |
| G7A | MG, MF, LE, SO | 2 | 2 | NULL | true | legacy_js_commented (pointed by G8) |
| G8A–G14A | MG, MF, LE, SO (+CO in G12A–14A) | 3 | 2 | NULL | true | legacy_js_commented |

`item_count` for ALA per matrix: G2:3, G3–G7:2, G8:1, G9:4, G10:2, G11:2, G12:4, G13:3, G14:2, G15:3.

### 5.2 `funcionesResultadosGlobal` → `global_scoring_rule` (per official group; message key in parentheses)

**G1** (3 rules): 10 `{"count":{"of":"red_areas","gte":1}}` → red · 20 `{"sum":{"of":["alert_signs","risk_factors"],"gte":2}}` → yellow (yellow_social, D18) · 90 `{"all":[]}` → green.

**G2–G4** (4 rules):
10 `{"any":[{"count":{"of":"red_areas","gte":1}},{"count":{"of":"yellow_areas","gte":2}},{"all":[{"count":{"of":"yellow_areas","gte":1}},{"sum":{"of":["alert_signs","risk_factors"],"gte":1}}]}]}` → red ·
20 `{"all":[{"any":[{"count":{"of":"yellow_areas","gte":1}},{"sum":{"of":["alert_signs","risk_factors"],"gte":2}}]},{"color_in":{"areas":["MG","MF","LE"],"is":"yellow"}}]}` → yellow (yellow_development) ·
30 `{"any":[{"count":{"of":"yellow_areas","gte":1}},{"sum":{"of":["alert_signs","risk_factors"],"gte":2}}]}` → yellow (yellow_social) ·
90 `{"all":[]}` → green.

**G5–G12** (4 rules): 10 `{"count":{"of":"red_areas","gte":1}}` → red · 20 `{"color_in":{"areas":["MG","MF","LE"],"is":"yellow"}}` → yellow (yellow_development) · 30 `{"color_in":{"areas":["SO"],"is":"yellow"}}` → yellow (yellow_social) · 90 `{"all":[]}` → green. **ALE+FRB intentionally absent (D15 — legacy bug preserved).**

**G13–G15**: same as G5–G12 but rule 30 uses `{"areas":["SO","CO"]}`. G15 tagged `source = extrapolated`.

Totals: 3 + 3×4 + 8×4 + 3×4 = 59 rules/version. Alternate groups: none.

### 5.3 Counters and messages

- `alert_signs` = count of ALE items answered with their yellow option (`totalElementosSIALE`); `risk_factors` = count of FRB answered yes (`totalElementosSIFRB`). Both are display counters ("en cifras") and grammar vocabulary — never semaphores.
- Messages (verbatim from `resultadoGlobal.phtml`): red — 'Riesgo de retraso en el desarrollo', bullets [continuar sesiones de interacción oportuna; referencia con especialista]; yellow_development and yellow_social — 'Rezago en el desarrollo', bullets [continuar sesiones; agendar aplicación de Prueba EDI en 3 meses; en caso de reincidencia, referencia con especialista] (texts identical in legacy, D17); green — 'Desarrollo normal', bullets [continuar sesiones].

### 5.4 Grammar (closed — D16)

```
condition  := all | any | not | count | sum | color_in
all        := {"all":  [condition, ...]}        // AND; [] = true (catch-all)
any        := {"any":  [condition, ...]}        // OR;  [] = false
not        := {"not":  condition}
count      := {"count": {"of": SET, ("gte"|"eq"|"lte"): int}}
sum        := {"sum":   {"of": [SET, ...], "gte": int}}
color_in   := {"color_in": {"areas": [AREA_CODE, ...], "is": COLOR_KEY}}  // true if ANY listed area holds the color
SET        := "red_areas" | "yellow_areas" | "green_areas" | "alert_signs" | "risk_factors"
```
Unknown operators, unknown SETs, unknown color keys, or `color_in` area codes without items in the rule's group are VALIDATION FAILURES (verify §7). Exactly one operator key per condition object.

## 6. Seeding pipeline (D20, D21)

```
backend/Modules/Instrument/database/seeders/
├── csv/
│   ├── _source/                      # raw legacy extracts, committed verbatim for provenance
│   │   ├── README.md                 # provenance: dump date, extraction notes
│   │   ├── cat_edi_clasificacion.csv | cat_edi_evaluacion.csv | cat_edi_frb.csv
│   │   ├── cat_edi_posicion.csv | cat_edi_perimetro_cefalico.csv | area_item_counts.csv
│   ├── edi-legacy-2013/
│   │   ├── age_group.csv             # code,name,ordinal,is_alternate,min_age_days,max_age_days,min_age_months,max_age_months,alternate_group_code,materials_note,legacy_id
│   │   ├── screening_item.csv        # group_code,area_code,code,question,position_key,sort_order,option_1_label,option_1_color,option_2_label,option_2_color,legacy_id
│   │   ├── risk_factor.csv           # code,question,sort_order,auto_derivation_key,legacy_id
│   │   ├── head_circumference_reference.csv  # sex_key,age_months,min_mm,max_mm,legacy_id
│   │   ├── area_scoring_rule.csv     # group_code,area_code,item_count,red_threshold,green_threshold,allows_yellow,source,notes   (HAND-WRITTEN)
│   │   ├── global_scoring_rule.csv   # group_code,sequence,result_color_key,message_key,condition,source,notes                    (HAND-WRITTEN)
│   │   └── global_result_message.csv # key,result_color_key,headline,referral_items                                               (HAND-WRITTEN)
│   └── edi-2022-2ed/                 # same files; item bank identical to legacy dir, rules per D14/D15
└── InstrumentSeeder.php
```

- Transform `_source` → per-version CSVs once (throwaway script or manual, output reviewed line-by-line; option colors mapped to keys green/yellow/red; sentinel ranges dropped; positions mapped to keys; H/M → male/female; cm → mm ×10).
- The two rule CSVs and messages are HAND-WRITTEN from §5 — not derivable from the dump. ~151 + ~59 + 4 rows per version: reviewable in a PR.
- `InstrumentSeeder` (registered in `DatabaseSeeder` after `CatalogSeeder`): per version — exists+published? recompute hash → match: skip; mismatch: abort. Else create draft → load CSVs (UUIDv5 ids, resolve catalog FKs by key) → run verify checks in-process → compute hash → set `published_at` (+ `is_default` for `edi-2022-2ed`).
- Version metadata (keys, names, modes, default flag, source_note) lives in a `VERSIONS` const array in the seeder.

## 7. `instrument:verify {version-key}` (runs in CI via Feature test)

Signature: `instrument:verify {version : instrument_version key}`. Loads the version, runs ALL checks, prints every failure, exit 1 if any (0 otherwise). Shared service `InstrumentVerificationService` used by both the command and the seeder's publish step.

| # | Check |
|---|---|
| V1 | Official groups: ranges NOT NULL, ordered by `min_age_days` they are contiguous (`next.min = prev.max + 1`), non-overlapping, first min = 30, last max = 2159. Alternates: both ranges NULL |
| V2 | Alternate pointers: every official except G1 points to a distinct `is_alternate` group; G1 points to none; no alternate is unreferenced or referenced twice; alternates point to nothing |
| V3 | Every item has exactly 2 non-deleted options, `sort_order` {1,2}, distinct `result_color_id` |
| V4 | Area rules: for every (group, area) pair with items and `scoring_kind = semaphore` exactly one rule with `item_count` == real count; no rule for pairs without items; no rule for counter areas; `red_threshold >= 1`; `green_threshold` NULL or `>= 0` |
| V5 | Alternate coverage: for every official group with alternate A, the set of development axes (`is_development_axis`) with items in the group equals the set with items in A |
| V6 | Global rules: every official group has ≥ 1 rule; unique sequences; the last (max sequence) condition is exactly `{"all":[]}` with color green; every condition validates against §5.4 (closed operators, SET vocabulary, known color keys, `color_in` areas have items in that group); alternates have zero global rules |
| V7 | Messages: the 4 keys exist; every rule's message belongs to the same version and `message.result_color_id == rule.result_color_id` |
| V8 | Version consistency: every content row's `instrument_version_id` equals its parent's (option→item→group→version; rules→group→version) |
| V9 | Percentile: unique (sex, month); `min < max`; per-sex months contiguous (no holes); all values > 0 |
| V10 | Published only: recomputed canonical SHA-256 == `content_hash` |

Canonical serialization (V10 + publish): JSON of `{version: {key, age_calculation_mode}, age_groups: [...], screening_items: [...], options: [...], risk_factors: [...], head_circumference_references: [...], area_scoring_rules: [...], global_scoring_rules: [...], global_result_messages: [...]}` — arrays sorted by natural key, fixed field order (natural keys + payload fields, ids and legacy_id EXCLUDED so the hash covers content, not identity), `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`, no whitespace.

## 8. Backend surface

- Models in `backend/Modules/Instrument/app/Models/`: 3 catalogs + 2 registry + 10 content (relations: version→groups→items→options; group→areaRules/globalRules; version→riskFactors/references/messages). `$hidden = ['legacy_id']` where applicable. Casts: booleans, `condition`/`referral_items` → `array`.
- `InstrumentContentObserver` registered for the 10 content models in the module service provider; `InstrumentVersionObserver` for the registry rule (D5).
- Controllers (read-only, `$keyForPermission = 'instrument'`): `InstrumentVersionController`, `AgeGroupController`, `ScreeningItemController` (includes `options`, filterable by `age_group_id`), `AreaScoringRuleController`, `GlobalScoringRuleController` (includes `message`), `RiskFactorController`, `HeadCircumferenceReferenceController`, `GlobalResultMessageController`. Routes in `Modules/Instrument/routes/api.php`: `Orion::resource(...)->only(['index','show'])` under `['auth:sanctum', 'initializeTenancy']` + `throttle:api`.
- Command `backend/Modules/Instrument/app/Console/InstrumentVerifyCommand.php` + `app/Services/{InstrumentVerificationService, InstrumentContentHasher, InstrumentCsvLoader}.php`.

## 9. Module registration (exact wiring)

- `modules_statuses.json`: `"Instrument": true`.
- `modules.csv`: `ed000002-0000-4000-8000-000000000001,Instrumento EDI,Core`.
- `permissions.csv`: 6 rows (`read/create/update/delete/restore/report.instrument`) referencing that module id, existing description style.
- `module_system.csv`: module → Core system `af21b731-78d5-4ca1-9bc0-4b4e4bdeadf2`, sort_order 30, group_label `EDI`.
- `SystemTableSeeder`: `seedModulePermissionEntities()` `'Instrumento EDI' => 'instrument'`; `seedModuleRoutes()` `'Instrumento EDI' => '/instrument'`; visible (Core hides itself from tenants); `seedModuleIcons()` `'Instrumento EDI' => 'IconClipboardHeart'` (verify the key exists in `frontend/src/shared/utils/iconMap.ts`; add the mapping if missing).
- `RolePermissionTableSeeder`: append the 6 permission names to `$permissionsOnlySuperAdmin` (line ~61) so tenant roles are excluded by the existing `whereNotIn` flow.
- `CatalogSeeder`: `seedResultColors()`, `seedDevelopmentAreas()`, `seedApplicationPositions()` via `upsertCatalog`.
- NO entries in `AngularController::$mapperSelects` nor `useCatalogs.ts::CatalogType` (D22) — document with a code comment so the module-registration checklist reflex does not add them later.

## 10. Frontend structure (read-only inspector)

- `frontend/src/modules/instrument/`: `schema.ts` (interfaces `IInstrumentVersion`, `IAgeGroup`, `IScreeningItem`, `IScreeningItemOption`, `IAreaScoringRule`, `IGlobalScoringRule`, `IRiskFactor`, `IHeadCircumferenceReference`, `IGlobalResultMessage` — no Zod form schemas: nothing is editable), `services/InstrumentResource.ts` (list/show wrappers over the read-only endpoints), `InstrumentVersionListPage.tsx`, `InstrumentVersionDetailPage.tsx` (tabs: Grupos — accordion per group with items + option color badges; Reglas por eje; Reglas globales — sequence, pretty-printed condition, message; Factores de riesgo; Perímetro cefálico — mm rendered as cm with one decimal for display).
- TanStack Query for fetching (no `createEntityStore` — there is no CRUD state), lazy per tab; UI text in Mexican Spanish; zero `any`.
- `App.tsx`: `<Route element={<PermissionRoute permissionEntity="instrument" />}>` with `/instrument` and `/instrument/:id`.

## 11. Open decisions

| Q | Question | Default encoded in this design |
|---|---|---|
| Q1 | G15 thresholds: does the 2013 2nd-ed / 2024 manual confirm the G9–14 extrapolation (D14)? 228 legacy G15 results exist with NO legacy engine — how they were produced is unknown | Seed extrapolated rules in both versions, `source = extrapolated`; the scoring-engine change must report G15 oracle mismatches separately, not fail the oracle |
| Q2 | Should `edi-2022-2ed` extend the ALE+FRB escalation (legacy G1–G4 only) to G5–G15, per the manual? | No — reproduce the legacy scope in both versions (D15); revisit with the manual before `edi-2024` |
| Q3 | Extend head-circumference coverage to months 60–71 (G15) with WHO/CDC data? | No — both versions keep the legacy 1–59 coverage (D19); extension is new clinical content for `edi-2024` |
| Q4 | Alternate adjudication was never executed in the legacy runtime (commented block). Is the per-area normalization of its pooled thresholds (D13) the correct reading for the engine? | Seed them tagged `legacy_js_commented`; the engine change owns replay semantics and may ignore them for the legacy version |
| Q5 | Version labels/keys: is `edi-2022-2ed` the right public name given its rules remain legacy-faithful (only G15 completed + calendar age mode)? | Keep the keys; `source_note` states exactly what differs from `edi-legacy-2013` |
| Q6 | Item `question` texts carry legacy `<br>` markup. Render as line breaks in the inspector or clean at transform time? | Keep verbatim in data; inspector converts `<br>` to line breaks at render (content stays hash-stable) |

## 12. Explicitly unchanged

`edi-clinic-and-child-registry` tables and modules, `sex` catalog content, `TenantCatalogSeeder::CATALOGS`, quota binding, `REPORT_MODEL_MAP`, notification resolvers. No `alter` migration touches any existing table. No screening/engine table is created here.

---

## 13. Empirical resolution of Q1 and Q4 (oracle evidence)

The 1,836 production screenings in the legacy dump were replayed against a Python
transcription of the legacy rules. Result: **1,834/1,836 = 99.9%**, and the two
remaining cases resolve the alternate-evaluation question outright. This is evidence
about what the system actually did, and it overrides the two readings below.

Fixture and replay script: extracted to a PII-free JSONL (`legacy_id`, group,
perinatal inputs, answers, expected colour). No name, CURP or person identifier.

### Q1 — RESOLVED: G15 is not an extrapolation

All **228** G15 screenings reproduce exactly under the G9–G14 rule
(`red_threshold = 2`, `green_threshold = 2`, `allows_yellow`). Match rate 228/228.

Seed G15 with `source = oracle_confirmed`, not `extrapolated`, and do NOT let the
scoring-engine change report G15 separately or exempt it from the oracle. Whatever
client produced those results used the G9–G14 rule; the missing
`funcionesResultados[15]` is a gap in this repo, not in the instrument.

### Q4 — RESOLVED: alternate adjudication DID run, and its rule is narrower than D13

The commented-out block at `index.phtml` L1150–1180 is not the code that ran. Two
screenings (`legacy_id` 791 and 2452) carry alternate items and their stored result
is only reproducible one way:

| | 791 | 2452 |
|---|---|---|
| Triggering area | MF | LE |
| Its own items | AMARILLO, AMARILLO, AMARILLO | AMARILLO, AMARILLO, AMARILLO |
| Alternate items | AMARILLO, AMARILLO, **ROJO** | AMARILLO, AMARILLO, **ROJO** |
| Stored result | ROJO | ROJO |

Three facts follow, and all three contradict the pooled-threshold reading in D13:

1. **The trigger is exactly "every item of a development area is AMARILLO."** Both
   cases show three own items all yellow, and no other area in either screening
   triggered.
2. **The area is re-scored using ONLY the alternate items**, not the union of own and
   alternate. Pooling all six under the group's own rule yields AMARILLO in both
   cases, which is not what production stored.
3. **The alternate rule uses `red_threshold = 1`**, even though group 10's own rule
   uses 2. One red among the three alternates is enough. Combined with the fact that
   alternate options can never produce green (`green_threshold NULL`), an alternate
   area resolves to ROJO or AMARILLO and never to VERDE.

Seed alternate `area_scoring_rule` rows with `red_threshold = 1` for every alternate
group and tag them `source = oracle_confirmed`. The scoring engine must replay them,
not ignore them.

### What remains genuinely open

The oracle settles **what the legacy did**, never **whether it was clinically right**.
Q2 stays open on exactly that basis: it is now confirmed that the legacy ignores
ALE + FRB in the global result from group 5 onward, but whether that is a defect of
the implementation or of the instrument is a question for the 2024 manual. Keep
`edi-legacy-2013` bug-compatible regardless; that version's job is to reproduce
history, not to be correct.

---

## 14. Correction to §13: the alternate rule was wrong

§13 concluded, from two screenings, that an area with alternate items is re-scored
**using only those items** under a `red_threshold = 1` rule. Replaying all 1,836
screenings against the **seeded** rules refutes it. Both models were run against the
same fixture and the same database rows:

| Model | Alternate handling | Match |
|---|---|---|
| **A** | Alternate items are **pooled with the area's own items** and scored under the area's own group rule | **1,834 / 1,836 = 99.89%** |
| B (§13) | Alternate items scored **alone**, `red_threshold = 1`, only when every own item is AMARILLO | 1,785 / 1,836 = 97.22% |

Model B's 51 extra failures are all "expected AMARILLO, computed ROJO" in groups 9–15:
one red among the alternates escalates the area under a threshold of 1, and production
did not escalate. The alternate trigger also fires in **855 areas**, not the 2 that §13
generalized from — answering alternate items is routine, not exceptional.

**Model A is the legacy behaviour.** §13's items 2 and 3 are withdrawn: the area is NOT
re-scored from the alternate items alone, and the alternate threshold is NOT 1.

What survives from §13: the Q1 resolution (G15 confirmed at 228/228, unchanged by this
correction, since neither model treats G15 specially) and the trigger description
itself, which is descriptive and harmless.

### What is still unexplained

The 2 screenings in Model A's residue (`legacy_id` 791 and 2452, group 10) store ROJO
where pooling yields AMARILLO. Both are reproducible **only** by the groups 2–4 global
rule, the one that escalates on ALE + FRB — which groups 9–15 are otherwise confirmed
to ignore. Two cases out of 1,836 is not enough to justify a rule; they belong in the
scoring engine's known-discrepancies file with reason `legacy_unexplained`, pending a
clinician's reading.

### Consequence for this change

The seeded alternate `area_scoring_rule` rows are now evidence-contradicted as an
independent scoring path. They stay in the data — they are a faithful record of what
the alternate sets contain — but the scoring engine MUST implement Model A and MUST
NOT consume them as a separate rule. Verified against the seeded rows, not against the
CSV transcription: the seed itself is exact (0 mismatches across 42,897 answer-colour
pairs, 0 of 451 items differing from the legacy catalog).
