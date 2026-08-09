# Delta for Amenity Reservations

> Adds the EMAIL delivery channel to 5 existing `amenity_reservation.*`
> notification events (R10 baseline: `openspec/changes/amenity-reservations/spec.md`).
> No existing R10 behavior changes — this is additive channel capability.

## ADDED Requirements

### Requirement: Email slug flattens dotted event keys
`BladeEmailChannel` MUST derive its Blade view name by replacing BOTH `_` and
`.` with `-` (`str_replace(['_','.'], '-', $slug)`), so a dotted event key
(`amenity_reservation.created`) resolves to a flat view
(`email::amenity-reservation-created`) instead of a nested, non-existent path.

#### Scenario: Dotted event key resolves to a flat view
- GIVEN event key `amenity_reservation.created`
- WHEN `BladeEmailChannel::renderContent()` derives the view name
- THEN it resolves `email::amenity-reservation-created`, not
  `email::amenity-reservation/created`

#### Scenario: Existing underscore-only keys are unaffected
- GIVEN any of the 20+ existing event keys with no dot
- WHEN the view name is derived
- THEN the resolved view name is byte-identical to before the change

### Requirement: Central catalog exposes email capability for the 5 events
`CatalogSeeder` MUST set `supports_email=true` and include `'blade_email'` in
`default_channels` for `amenity_reservation.{created,approved,
payment_confirmed,payment_rejected,surcharge_added}` only. The other 6
`amenity_reservation.*` events (including `deposit_confirmed`) MUST remain
`supports_email=false`.

#### Scenario: Re-seeding flips capability for the 5 events
- GIVEN `CatalogSeeder::seedNotificationEvents()` runs (upsert)
- WHEN the 5 target events are seeded
- THEN their `notification_event.supports_email` is `true` and
  `default_channels` includes `blade_email`

#### Scenario: Out-of-scope events stay email-off
- GIVEN `amenity_reservation.deposit_confirmed` or any other non-target event
- WHEN `CatalogSeeder` re-seeds
- THEN `supports_email` remains `false`

### Requirement: Existing tenants receive the email channel via one-shot migration
A reversible migration MUST set `channel_email=true` on existing
`notification_setting` rows for the 5 target events (referencing their
`NotificationEvent` UUID constants), leaving all other events untouched. The
migration MUST be idempotent (safe to re-run) and `down()` MUST restore
`channel_email=false`.

#### Scenario: Migration flips only the 5 target rows
- GIVEN a tenant with pre-existing `notification_setting` rows for all
  `amenity_reservation.*` events at `channel_email=false`
- WHEN the migration runs
- THEN only the 5 target events' rows have `channel_email=true`; other
  `amenity_reservation.*` rows are unchanged

#### Scenario: Migration is idempotent
- GIVEN the migration already ran once
- WHEN it runs again
- THEN no error occurs and the affected rows remain `channel_email=true`

### Requirement: Board email context identifies the unit and resident
`amenity_reservation.created` email context MUST additively include unit and
resident identification alongside the existing `amenity_name` and
`reservation_date`, without altering the in-app/push template's context usage.

#### Scenario: Created email carries unit/resident identity
- GIVEN a resident creates a reservation
- WHEN the board `created` email is rendered
- THEN its context includes `amenity_name`, `reservation_date`, and unit/resident
  identifiers
- AND the in-app notification for the same event is unaffected

### Requirement: Designed templates render real HTML, no plain-text fallback
Each of the 5 events MUST have a corresponding `frontend/emails/*.tsx`
template compiled via `pnpm copy-emails` to a flat, correctly-named Blade view
under `backend/Modules/Email/resources/views/`, rendering styled HTML content
(not the `Log::warning` plain-text fallback) with all context variables
substituted (no empty gaps).

#### Scenario: Each event's view exists and renders
- GIVEN the 5 compiled Blade views exist
- WHEN `view('email::{slug}', $context)->render()` is called for each event
  with representative context
- THEN it renders HTML content and does not throw a view-not-found error

### Requirement: Admin config exposes the email toggle without frontend changes
Configuración → Notificaciones MUST show an email `Switch` for each of the 5
events once `supports_email=true`, using the existing data-driven rendering
with zero changes to `NotificationsConfigSection.tsx` or
`ConfigurationResource.ts`.

#### Scenario: Toggle appears after catalog flip
- GIVEN an admin opens Configuración → Notificaciones after the catalog change
- WHEN they view one of the 5 events
- THEN an email toggle is visible and reflects the tenant's
  `notification_setting.channel_email`

### Requirement: Feature tests cover dispatch and real rendering per event
For each of the 5 events, a feature test MUST assert `blade_email` is
dispatched (via `Queue::fake()`/`assertPushed` or `Mail::fake()`/
`assertSent`) AND a separate real-render assertion MUST call
`view('email::{slug}', ...)->render()`, since `Mail::fake()` does not render
Blade and cannot catch a slug/filename mismatch.

#### Scenario: Wiring test catches a filename mismatch
- GIVEN a template filename does not match its flattened event key
- WHEN the real-render test executes
- THEN the test fails (view not found), rather than silently passing under
  `Mail::fake()`

### Requirement: End-to-end delivery verified against Mailpit
For each of the 5 events, triggering the domain action in a dev environment
with the migration applied MUST result in a real email landing in Mailpit
(SMTP `127.0.0.1:1025`) rendered as styled HTML with correct subject and copy.

#### Scenario: Real send lands in Mailpit
- GIVEN the 5 events are wired, seeded, and migrated
- WHEN each domain action is triggered in dev
- THEN Mailpit (`http://localhost:8025`) shows a styled HTML email per event
  with correct subject and Spanish copy
