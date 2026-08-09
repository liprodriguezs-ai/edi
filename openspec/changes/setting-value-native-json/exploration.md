# Exploration — setting-value-native-json

> Phase: `sdd-explore` · Status: complete · Artifact store: hybrid
> Engram topic: `sdd/setting-value-native-json/explore` (observation #2301)

## Goal

`setting.value` is a `text` column in a generic per-tenant key-value table. Every writer
hand-serializes, and a sibling `type` column (`string|boolean|integer|json`) drives manual
deserialization in `getResolvedValueAttribute()`. Explore replacing that with a native
Eloquent cast so Laravel serializes/deserializes on its own.

## Current state

- Migration `backend/Modules/Setting/database/migrations/2026_02_26_135348_create_setting_table.php`:
  `text('value')->nullable()`, `string('type')->default('string')`, `unique(['tenant_id','slug'])`
  (NOT partial), tenant-scoped via `BaseModel::columns($table, true)` so also soft-deleted.
- `Setting::casts()` is `['value' => 'string']` — an identity cast.
- `getResolvedValueAttribute()` branches on `$this->type`: boolean via a permissive
  `in_array(strtolower($raw), ['true','1','yes'])`, integer via `(int)`, json via `json_decode`,
  default `(string)`. It special-cases `null`/`''` → `false` for boolean, `null` otherwise.
- `Setting::putValue($slug, string $value, string $type)` (landed on `main` as `fb07bfa`) is
  hard-typed `string $value`, so **every caller pre-serializes** before calling it.
- `SettingController` (Orion, live at `/setting`) exposes `type` in `filterableBy()`/`sortableBy()`;
  `SettingCreateRequest`/`SettingUpdateRequest` validate `type in:boolean,string,integer,json`.
  No frontend module consumes this generic resource or reads a Setting `.type` field.

Proven by tinker round-trip: a single `'value' => 'json'` cast correctly resolves every type this
table stores (`2`→int, `true`/`false`→bool, `"R-2026-000123"`→string, `[3,1,0]`→array), because
JSON is self-describing. The original premise for the manual `type` column does not hold **for reads**.

## The central risk — silent double-encoding

Adding a native cast **without rewriting every writer in the same change** silently corrupts data:

```
putValue($slug, 'true', TYPE_BOOLEAN)
  → cast json_encode('true') → stores "true"   (a JSON *string*, not JSON true)
  → read json_decode('"true"') → PHP string "true", not boolean true
```

Callers doing strict comparisons — `AmenityReservations/Http/Middleware/EnsureAmenityReservationsEnabled.php:51`,
`ConfigurationController.php:443` — would silently flip feature gates to always-false. **No crash,
just wrong behavior.** `updateNotificationTemplates()` and `NotificationTemplateSeeder` already
`json_encode()` before calling `putValue()`, so templates would double-encode too.

Minimum coherent scope is therefore:

1. `putValue()` signature `string $value` → `mixed $value`; strip pre-serialization from every caller.
2. Rewrite all **11 production** + **~40 test** write call sites across **~26 files** in lockstep.
3. Fix `ConfigurationController::getNotificationTemplates()` (lines 474-489), which bypasses the
   accessor entirely and hand-decodes `$setting->value`.
4. One-time data migration re-encoding every existing row per its current `type`.

## Measured blast radius

| Kind | Count | Notes |
|---|---|---|
| `putValue()` writes | 6 | 5 in `ConfigurationController` (416, 504, 1761, 1902, 2019), 1 in `NotificationTemplateSeeder:142` |
| `Setting::create()` | 2 | `CollectionsDatabaseSeeder:49`, `AmenityReservationsDatabaseSeeder:39` |
| `Setting::firstOrCreate()` | 3 | `TenantCatalogSeeder:304` and `:371`, plus the legacy migration `2026_07_25_000001_…:42` |
| `->resolved_value` reads | 7 | `SettingService:17`, `DelinquencyRestrictionService:334`, `Setting:45`, `ConfigurationController` ×4 |
| `Setting::getValue()` reads | ~18 | (two earlier "hits" were code comments, not calls) |
| Test fixture writes | ~40+ | ~20 files under `tests/Feature/**` and `Modules/*/tests/Feature/**` |

Total ≈ 50 call sites over ≈ 26 files. **Very likely exceeds the 400-line review budget.**

## Live data check (resolved the open unknown)

Ran against the local dev database (`residia`, 235 rows):

| type | rows | nulls | empty strings | soft-deleted |
|---|---|---|---|---|
| boolean | 65 | 0 | 0 | 0 |
| integer | 35 | 0 | 0 | **15** |
| json | 125 | 0 | 0 | 0 |
| string | 10 | 0 | 0 | 0 |

- **No NULL and no empty-string values exist** — the accessor's `null`/`''` special case is
  currently dead data-wise, though it must still be preserved defensively.
- Boolean rows store exactly `true`/`false` — **no legacy `'1'`/`'yes'` values** to normalize.
- String rows are stored **bare** (e.g. free-text Spanish notices), i.e. invalid JSON as-is —
  these are the rows the data migration must re-encode.
- 15 soft-deleted rows exist, which independently corroborates the production bug fixed in `fb07bfa`.

> Caveat: this is the local dev database. The same census must be re-run against production before
> the migration ships, since prod may hold rows this dataset does not.

## Decisions taken

**Keep the `type` column.** JSON is self-describing on read, but `type` still documents authoring
intent, lets the FormRequests validate shape without decoding, and is a live Orion API contract.
Dropping it adds churn across ~40 test fixtures and reduces no risk — the risk lives entirely in
the write-side value shape.

**Keep the column as `text` + a PHP-level cast. Do NOT convert to `jsonb`.**
`OnboardingChecklistService.php:104-108` runs a raw `where('value','!=','')` text comparison that
Postgres will not implicitly cast against `jsonb`, and an `ALTER … USING value::jsonb` hard-fails
transactionally on any not-yet-valid row instead of degrading per row. Nothing queries JSON
operators today, so the extra risk buys nothing.

## Approaches considered

1. **Status quo** — zero risk, keeps the duplication the user objects to.
2. **Native `json` cast, `text` column, keep `type`, thin `getValue()`/`putValue()` wrappers** — RECOMMENDED.
3. **Native cast and drop `type`** — same core risk plus fixture/API churn, no functional gain.
4. **Convert to `jsonb`** — breaks the raw query, hard-fails on bad rows. Strictly worse than 2.

## Recommendation

Approach **2**, delivered in slices rather than one change:
(a) model + migration + data remediation, (b) production writer call sites,
(c) test-fixture call sites, (d) the `getNotificationTemplates()` bypass fix.

## Open risks

- Silent double-encoding if any writer is missed during cutover — the dangerous failure mode.
- Production data census not yet run (local census above is clean).
- The `/setting` Orion CRUD is a live public contract; a FormRequest change is an API change.
- Blast radius very likely exceeds the 400-line review budget → needs an explicit size decision.

## Ready for proposal

Yes — provided `sdd-propose`/`sdd-design` treat this as a full-touch refactor, not a schema tweak.
