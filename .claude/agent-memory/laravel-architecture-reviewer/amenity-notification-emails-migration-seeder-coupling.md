---
name: amenity-notification-emails-migration-seeder-coupling
description: First-ever precedent of a data migration invoking a Seeder class directly (2026_07_19_000001), plus a shared-context comment-accuracy gap in ReservationService's email-only context claim
metadata:
  type: discovery
---

Reviewed the `amenity-notification-emails` SDD change (S10 email channel wiring
for 5 amenity_reservation.* events): `BladeEmailChannel::renderContent()` slug
flattening (`_` AND `.` → `-`), `CatalogSeeder`'s 5-event `supports_email`/
`default_channels` flip, `2026_07_19_000001_enable_amenity_reservation_emails_
on_existing_settings.php`, `ReservationService::notifyReservationCreated()`'s
unit_label/resident_name addition, and 3 test files. Overall solid: CatalogSeeder
change is a correct central-catalog `upsertCatalog()` (constant UUIDs), the
BladeEmailChannel diff is narrowly scoped exactly to slug-flattening (verified
via `git diff`, no undisclosed scope creep), and test methodology is strong
(idempotency, non-scope isolation, backfill-for-pre-S10-tenant, down()
reversibility, per-event Queue::assertPushed, plus one genuine effect-level
array-mail-transport end-to-end test with a correctly-reasoned Mail::fake()
pitfall writeup in the test docblock).

**New architectural precedent (WARNING, not blocking):** this is the FIRST
migration in the repo that invokes a Seeder class directly inside `up()`
(`(new TenantNotificationSettingSeeder)->run()` per-tenant via
`Tenant::all()->each(fn($t) => $t->run(...))`). Verified via
`grep -rl "Seeder)->run()" Modules/*/database/migrations` — no other migration
does this; `2026_06_27_000002_create_notification_setting_table.php` only
*mentions* the seeder in a table comment, never invokes it. Coupling a migration
to a seeder class is a latent risk: the seeder's board-role backfill
(`seedAmenityReservationCreatedBoardRoles()`) is NOT re-guarded by this
migration the way `channel_email` is (step 2 explicitly re-flips
`channel_email=true` regardless of seeder defaults, but board-role assignment
has no equivalent explicit re-assertion) — if `TenantNotificationSettingSeeder`
evolves its default board-role logic later, a FRESH install replaying this OLD
migration file would silently pick up the new logic instead of what this change
actually intended. Functionally safe today (verified idempotent + reversible by
`EnableAmenityReservationEmailsMigrationTest`), and the whole migration IS
transaction-safe end-to-end: single-database mode means `$tenant->run()` only
swaps the tenancy scope, never the connection, and Laravel wraps the migration
`up()` in one DB transaction by default — so a mid-loop tenant failure rolls
back atomically, no partial-application risk. Flag this precedent again if a
future migration follows the same pattern with a seeder that has LESS
idempotent side effects than firstOrCreate.

**Comment-accuracy gap (WARNING), same recurring pattern as
[[amenity-reservations-s9-s11]]'s finding #1:** `ReservationService::
notifyReservationCreated()`'s comment claims `unit_label`/`resident_name` are
added "to the email context (only, not the in-app template)" — but the
implementation adds them to the ONE shared `$context` array passed to both
`email_context` AND the in-app template substitution inside
`NotifiesResidents::notifyEvent()`/`notifyUsersFromTemplate()` (`TemplateRenderer::
render($slug, $context)` uses the SAME array). Today it's harmless because no
in-app title/body template references `{unit_label}`/`{resident_name}`, but the
comment overstates a guarantee the code doesn't structurally enforce — an admin
editing the in-app template via the Configuration UI to add `{unit_label}` would
silently start rendering it. Correct the comment (or actually split
email-only vs shared context) rather than relying on template authors never
using those tokens.

**Test coverage gap (WARNING) — exactly the kind of gap the team asked for:**
no test proves `notifyReservationCreated()`'s new unit_label/resident_name
fields flow end-to-end into a real rendered email. `AmenityReservationEmailRenderTest`
hand-supplies both keys directly to `view('email::amenity-reservation-created', [...])`
(proves the BLADE TEMPLATE can render them, not that the SERVICE produces them
correctly). `AmenityReservationNotificationWiringTest::
test_created_dispatches_blade_email_when_channel_enabled()` only asserts
`Queue::assertPushed(..., channel === 'blade_email' && recipients === [...])` —
never inspects the job's context/payload. The one "effect-level" array-mail-
transport test covers the `approved` event (which has no unit_label/resident_name),
not `created`. Recommend extending either the dispatch test or the effect-level
test to assert the rendered/queued email actually contains the unit label and
resident name for the `created` event.
