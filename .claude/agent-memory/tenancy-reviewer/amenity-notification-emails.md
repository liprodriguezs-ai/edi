---
name: amenity-notification-emails
description: SDD amenity-notification-emails review — fleet-wide data migration pattern (Tenant::all()->run(seeder) + global DB::table update on central UUIDs) is SAFE; soft-delete blind spots WARNING
metadata:
  type: project
---

Reviewed 2026-07-19. Change: enable email channel on 5 amenity_reservation.* events.

**Fleet-wide data-migration pattern — VERIFIED SAFE (do not re-flag):**
`2026_07_19_000001_enable_amenity_reservation_emails_on_existing_settings.php`:
- `Tenant::all()->each(fn ($t) => $t->run(...))` + a scope-bypassing `DB::table('notification_setting')->whereIn('notification_event_id', [5 central UUID constants])->update(...)`.
- Stancl `TenantRun::run()` (vendor, v3.10) saves `tenant()`, initializes, then RESTORES the original tenant or `tenancy()->end()` — no context leak between loop iterations, and safe even if the migration runs with tenancy already initialized.
- Project `Tenant` model uses `SoftDeletes` → `Tenant::all()` excludes deleted tenants. Blocked/pending_payment tenants ARE included — deliberate and correct (their config must be right on resume).
- The global UPDATE without `tenant_id` filter is the correct pattern for a deliberate all-tenant backfill on rows keyed by CENTRAL catalog UUIDs; isolation is preserved because the whereIn is on central constants, not tenant data.

**WARNING pattern to watch in future fleet migrations (soft-delete blindness):**
1. `DB::table(...)->update()` does not filter `deleted_at` → soft-deleted `notification_setting` rows get flipped too (restore would resurrect with the new value). Low impact (nothing soft-deletes settings today).
2. Re-running `TenantNotificationSettingSeeder` fleet-wide: `firstOrCreate` is blind to trashed rows; a soft-deleted setting row would cause an INSERT violating `unique(['tenant_id','notification_event_id'])` and abort the migration mid-loop (partial state). Latent, pre-existing seeder trait amplified by fleet-wide re-run.

**Other verified-safe pieces:**
- `CatalogSeeder` flip of `supports_email`/`default_channels` touches only CENTRAL `notification_event` — no tenancy dimension.
- `ReservationService::notifyReservationCreated` enrichment (`Unit::find`, `User::find`): runs in initialized request context; Unit is TenantScope'd and UUID PKs make cross-tenant `find` collisions impossible; User is central by design. Context is frozen into the notification payload at dispatch (`NotifiesResidents` L235-236 sets `email_template`/`email_context`) inside tenant context → the queued channel job is self-contained.
- `BladeEmailChannel` slug flattening (`str_replace(['_','.'],'-')`): slug comes from code-literal central event keys (never user input), views are global flat Blade files by design, channel does zero tenant queries. No cross-tenant template resolution risk.

See [[notification-module-isolation]] and [[amenity-reservations-s9-s11]] for prior context.
