# Design: Setting Value Native JSON Cast

> Change: `setting-value-native-json` · Phase: `sdd-design` · Store: hybrid · Input: proposal.md (obs #2302), exploration.md (obs #2301)

## Technical Approach

One atomic cutover: `Setting::casts()` → `['value' => 'json']` on the existing `text` column. `type` stays as authoring metadata and becomes a **live write-time invariant** via a permanent type-consistency guard on the model's `saving` event. Read contract preserved by a retained thin `resolved_value` accessor. A quiet, idempotent, reversible data migration re-encodes only rows whose bytes actually change (string-typed rows + legacy boolean spellings; canonical `true`/`false`, integers, and json rows are already valid JSON byte-for-byte).

## Architecture Decisions

### D1 — Guard location: model `saving` event (not `putValue()`)

| Option | Tradeoff | Decision |
|---|---|---|
| Guard in `putValue()` only | Misses `create()`/`firstOrCreate()`/`updateOrCreate()`/`fill()+save()` — the untyped paths no signature catches | Rejected |
| Guard in both | Duplicate logic, same coverage as `saving` alone | Rejected |
| `static::saving()` in `booted()` | Every Eloquent write funnels through `saving`, including `putValue()` itself | **Chosen** |

Residual bypasses: `Builder::update()` mass updates and `saveQuietly()` skip model events — no production Setting site uses either (grep-verified); the data migration deliberately uses the raw-builder path as trusted actor.

### D2 — Guard is PERMANENT, with a heuristic-free rejection domain

The crux ("string value" vs "pre-serialized payload") is **not decidable by sniffing JSON-ness** for `type=string` — `json_encode('foo')` is also a string. So the guard does NOT probe strings. Instead: the cast decodes on read; the guard checks the **decoded PHP type against the declared `type`**:

| declared `type` | accepted decoded value | rejected → throws `InvalidArgumentException(slug, declared type, actual PHP type)` |
|---|---|---|
| boolean | `bool`, `null` | string/int/float/array |
| integer | `int`, `null` | string/bool/float/array |
| json | `array`, `null` | string/bool/int/float (scalar payloads belong under `type=string`; all 125 local json rows are arrays) |
| string | `string`, `null` | bool/int/float/array |

Zero false positives: a genuine string under `type=string` always passes. Stated limitation: under `type=string`, pre-serialization (`'"foo"'`) is formally indistinguishable and stays undetected — but corruption there is cosmetic (stray quotes), flips no `=== true` gate, and round-trip tests cover the known writers. Permanent because: O(1) check on rare writes; blocks future-writer regression; already proven live — uncommitted billing WIP (`tests/Feature/Billing/EntitlementServiceTest.php:131`) writes `'5'` under `TYPE_INTEGER` and would corrupt silently without it, fail loudly with it.

### D3 — FormRequest `value` validation: shared strict invokable rule

New `SettingValueRule(string $type)` enforcing exactly the D2 table (Laravel's built-in `boolean`/`integer` rules accept coercible strings → would 500 on the guard; strict rule returns 422). `value` stays `nullable`. Create: type from `$this->input('type')`. Update: `sometimes` type falls back to the routed model's current `type`.

### D4 — Data migration (up/down, quiet, idempotent)

`Setting::withoutGlobalScope(TenantScope::class)->withTrashed()->chunkById(200, ...)`. Per row: read **raw** bytes via `getRawOriginal('value')` (the cast would decode); write via PK-keyed builder update (no events → no audit rows, no timestamp churn, guard N/A for the trusted actor).

`up()` per raw/`type`: already valid JSON matching D2 table → skip (idempotency). boolean `'1'/'yes'`→`true`, `'0'/'no'`→`false`. string → `json_encode(raw)` (incl. numeric-looking strings like `'123'` → `'"123"'`). integer/json non-conforming, empty strings, and any other disagreement → **abort the whole migration before the first write** (`preflightOrFail()` runs the same `decide()` over every row first and throws with per-type counts + a bounded sample of offending slugs) — never guess, never half-migrate. This supersedes the original skip-and-log design: with `migrate --force` on deploy nobody inspects the data first, so a silently-skipped row becomes a silently-emptied setting. It also removes the manual pre-deploy census from the operator's plate — the migration censuses itself. Unmigrated rows stay read-safe: cast yields `null`, accessor degrades, nothing crashes.
`down()`: only `type=string` rows that are valid JSON strings → write decoded bare string; boolean/integer/json rows are left untouched. Reversibility is why `type` stays.

**Precision on what `down()` restores** (sdd-verify W-1): it restores *correct reads*, NOT byte-for-byte originals. Canonical `true`/`false`, integer and json rows genuinely are byte-identical in both worlds, so leaving them untouched is exact. But the legacy boolean spellings `up()` rewrote (`'1'`/`'yes'` → `true`, `'0'`/`'no'` → `false`) are NOT restored to their original bytes. This is functionally benign — the pre-change accessor's permissive `in_array(strtolower($raw), ['true','1','yes'])` check reads `'true'` correctly — but the rollback claim must be stated as "reads resolve correctly again", never as "the original bytes come back".
Known ambiguity: a bare string that IS a valid JSON string literal (user literally stored `'"hello"'`) would be skipped as already-migrated — the mandatory per-type census sample exists to spot exactly this.

### D5 — null/empty semantics: thin retained `resolved_value`

`getResolvedValueAttribute(): mixed` becomes `return $this->value ?? ($this->type === self::TYPE_BOOLEAN ? false : null);`. Keeps all 7 `->resolved_value` readers and `getValue()` byte-identical; the `json` cast maps `''`→`null`, so empty behaves like null — boolean→`false`, else `null`, matching today.

## Data Flow

    writer (native PHP value)
      → putValue(mixed)/create/firstOrCreate ─→ json cast (encode) ─→ saving guard (D2) ─→ text column
    reader
      → text column ─→ json cast (decode) ─→ resolved_value (null→false for boolean) ─→ getValue()

## File Changes

| File | Action | Description |
|---|---|---|
| `Modules/Setting/app/Models/Setting.php` | Modify | cast, `booted()` guard, `putValue(string, mixed, string)`, thin accessor |
| `Modules/Setting/app/Rules/SettingValueRule.php` | Create | strict D2-table validation rule |
| `Modules/Setting/app/Http/Requests/Setting{Create,Update}Request.php` | Modify | `value` → `['nullable', new SettingValueRule($type)]` |
| `Modules/Configuration/.../ConfigurationController.php` | Modify | 5 `putValue` sites (416, 504, 1761, 1902, 2019) + `getNotificationTemplates()` bypass (474-489) reads `$setting->value` |
| `Modules/Configuration/.../NotificationTemplateSeeder.php:142`, `database/seeders/TenantCatalogSeeder.php:304,371`, `Modules/{AmenityReservations:39,Collections:49}` seeders, `Modules/Configuration/database/migrations/2026_07_25_000001_*:42` | Modify | pass native values |
| `Modules/Setting/database/migrations/{ts}_reencode_setting_value_as_json.php` | Create | D4 migration |
| `backend/tests/**`, `Modules/*/tests/**` (~20 files, ~40 sites + `tests/Feature/Billing/EntitlementServiceTest.php:131`) | Modify | native fixture values + new round-trip tests |

## Interfaces / Contracts

```php
public static function putValue(string $slug, mixed $value, string $type): static; // was string $value
// saving guard throws \InvalidArgumentException("Setting '{slug}': type '{type}' rejects {php_type} value")
```

## Testing Strategy

| Layer | What | Approach |
|---|---|---|
| Feature | Round-trip per type via `putValue`/`getValue` AND raw `create`/`firstOrCreate`/`updateOrCreate` | Written FIRST against current contract; must pass before and after |
| Feature | Guard rejections (one per D2 row) + null/empty semantics (boolean null→false) | expectException / assertSame |
| Feature | Migration idempotency: run twice, second run = 0 changed rows; skip-and-log on a seeded disagreeing row | artisan migrate in test |
| Regression | `OnboardingChecklistService:104-108` raw text query, `getNotificationTemplates()` output | existing suites + byte check |

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary.

## Migration / Rollout

**Hard precondition — production census** (never yet run; local 235-row census is clean but local-only):

```php
Setting::withoutGlobalScope(\Stancl\Tenancy\Database\TenantScope::class)->withTrashed()
    ->selectRaw("type, count(*) total, count(*) filter (where value is null) nulls, count(*) filter (where value = '') empties")
    ->groupBy('type')->get();
// plus per type: ->where('type', $t)->distinct()->limit(50)->pluck('value')
```

**Work order (tree never silently-corrupting midway):** (1) round-trip tests (old contract, green) → (2) THE ATOM: cast + guard + wrappers + FormRequests/rule + all writer rewrites + bypass fix, never split → (3) migration file (inert until `migrate`) → (4) sweep + full suite. Mid-atom = failing tests/guard exceptions (loud); post-atom pre-migrate = old string rows read as null (degraded, not corrupted).

**Mechanical missed-site detection** (not by eye): guard exception in any test run; plus the sweep — every hit manually cleared:

```bash
grep -rn --include='*.php' --exclude-dir=vendor --exclude-dir=worktrees -E \
  "putValue\(|Setting::(create|firstOrCreate|updateOrCreate)" backend | grep -v '\.claude'
grep -rn --include='*.php' --exclude-dir=vendor -B1 -A5 "Setting::" backend | grep -E "json_encode|\(string\)\s"
```

Never edit `backend/.claude/worktrees/**` copies (parallel WIP; hard constraint). `EntitlementServiceTest.php:131` is uncommitted billing-session WIP — rewrite `'5'`→`5` is one token, but flag it in apply so the parallel session isn't surprised.

**Rollback:** pre-`migrate` — revert code only, data untouched. Post-`migrate` — `php artisan migrate:rollback` (the `down()` above), then revert code; skipped rows were never modified.

## Open Questions

- [ ] None blocking. Production census result may add rows to the skip-log remediation list (human review by design).
