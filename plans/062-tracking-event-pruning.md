# Plan 062: Prune the `tracking_event` table (bounded retention like every other log here)

> **Executor instructions**: Follow step by step; verify each step. Update
> `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git -C backend diff --stat 8117e66..HEAD -- Modules/Tracking/app/Models/TrackingEvent.php routes/console.php`

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: tech-debt (unbounded growth)
- **Planned at**: backend `8117e66`, 2026-07-01

## Why this matters

`tracking_event` is a central (no `tenant_id`) log with one row per (event_id, channel)
plus a full `request_payload` JSON blob, written for every dispatched marketing conversion
(Lead / StartTrial / CompleteRegistration / InitiateCheckout / Purchase) from the landing
AND the app — and `/api/track` is public (throttled 60/min per IP). It has no `Prunable`
trait and no scheduled prune, while `routes/console.php` prunes telescope, sanctum, and
media on a schedule. So it accumulates forever on the shared Postgres DB, driven by
anonymous traffic — inconsistent with the pruning discipline everywhere else.

## Current state

- `backend/Modules/Tracking/app/Models/TrackingEvent.php` (whole file): `extends BaseModel`,
  `use CentralConnection`, `$table = 'tracking_event'`, fillable event fields + a
  `request_payload` array cast. No `Prunable`/`MassPrunable`, no `created_at` index noted.
- `backend/routes/console.php` (lines 11–23): the established prune pattern —
  ```php
  Schedule::command('telescope:prune --hours=72')->daily();
  Schedule::command('sanctum:prune-expired --hours=2160')->daily();
  Schedule::command('media:prune-cache')->dailyAt('00:00');
  Schedule::command('media-library:clean')->weekly();
  ```
  No `tracking:*` entry.

Conventions: use Laravel's `Prunable`/`MassPrunable` (Laravel 13). Retention should be
config-driven (`config('tracking....')` with an env fallback — but read config in code,
never `env()` outside config files, per repo rule 4.4).

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Syntax | `cd backend && php -l Modules/Tracking/app/Models/TrackingEvent.php` | clean |
| Lint | `cd backend && vendor/bin/pint --dirty` | exit 0 |
| Test | `cd backend && php artisan test --filter=Tracking` | all pass incl. new prune test |
| Prune dry-run | `cd backend && php artisan model:prune --pretend --model="Modules\Tracking\Models\TrackingEvent"` | lists candidates, no delete |

## Scope

**In scope**:
- `TrackingEvent.php` — add `MassPrunable` (or `Prunable`) + a `prunable()` query on
  `created_at` older than a config-driven retention (default e.g. 90 days).
- `backend/Modules/Tracking/config/config.php` (or the existing tracking config) — add a
  `retention_days` key reading an env fallback.
- A migration adding a `created_at` index IF one doesn't already exist (check the
  `tracking_event` create migration first).
- `routes/console.php` — schedule `model:prune` for the model (daily), next to the others.
- Test.

**Out of scope**:
- Changing what tracking writes or the dispatch pipeline.
- Making the table tenant-scoped (it's intentionally central).

## Steps

### Step 1: Make the model prunable

```php
use Illuminate\Database\Eloquent\MassPrunable;
// ...
class TrackingEvent extends BaseModel
{
    use CentralConnection, HasFactory, MassPrunable;

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays((int) config('tracking.retention_days', 90)));
    }
}
```
Add `tracking.retention_days` to the tracking config (env-backed). Confirm `created_at`
exists (it does via `BaseModel::columns`) and is indexed; if not, add an index migration.

**Verify**: `php -l` clean; `php artisan model:prune --pretend --model="Modules\Tracking\Models\TrackingEvent"` lists old rows without deleting.

> NOTE: `MassPrunable` skips model events / soft-delete — appropriate for a pure log.
> If the table uses `SoftDeletes` via BaseModel and you want hard deletes, `MassPrunable`
> is correct; confirm the desired semantics (a debug log should hard-delete).

### Step 2: Schedule the prune

In `routes/console.php`, add next to the other prunes:
```php
Schedule::command('model:prune', ['--model' => \Modules\Tracking\Models\TrackingEvent::class])->daily();
```

**Verify**: `php artisan schedule:list` shows the tracking prune.

### Step 3: Test

Feature/unit test: seed a row with `created_at` older than retention + a recent one; run
the prune; assert only the old one is gone.

**Verify**: `php artisan test --filter=Tracking` green.

### Step 4: Format

**Verify**: `vendor/bin/pint --dirty` → exit 0.

## Done criteria

- [ ] `TrackingEvent` is prunable on a config-driven `created_at` retention
- [ ] `created_at` is indexed (verified or added)
- [ ] `model:prune` scheduled daily in `routes/console.php`
- [ ] Prune test green
- [ ] `php -l` clean, `vendor/bin/pint --dirty` exit 0
- [ ] `plans/README.md` row updated

## STOP conditions

- The model or console file differs from the excerpt (drift).
- `BaseModel` forces `SoftDeletes` such that `MassPrunable` deletes conflict with the audit
  observer — if the GlobalObserver requires soft-delete semantics on this table, use
  `Prunable` (per-model) instead and report.

## Maintenance notes

- Retention is config-driven; document `TRACKING_RETENTION_DAYS` in `.env.example` (fold
  into plan 067's docs sweep).
- Reviewer: confirm the prune targets `created_at` (not `updated_at`) and that the index
  supports the delete's WHERE.
