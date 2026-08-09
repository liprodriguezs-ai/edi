# Notification Mandatory Events Specification

## Purpose

Defines the `is_mandatory` mechanism on the central `notification_event` catalog: a flag that lets a future critical event ship where the admin can tune delivery channels but MUST NOT be able to turn the event off. Enforcement lives in the backend — the client is never trusted. Phase 0 introduces the mechanism only; all 12 currently seeded events stay `is_mandatory = false`, so no existing tenant sees a behavior change from this alone.

## Requirements

### Requirement: Central catalog column `is_mandatory`

`notification_event.is_mandatory` MUST be a `NOT NULL boolean` column with a default of `false`, added via a migration on the CENTRAL table (no `tenant_id`, no `BelongsToTenant`) with a `->comment()` describing its meaning. `NotificationEvent` MUST include `is_mandatory` in `$fillable` and cast it to `boolean`. `CatalogSeeder::seedNotificationEvents()` MUST upsert all 12 existing rows with `is_mandatory => false`.

#### Scenario: Migration is safe on existing data

- GIVEN a database with the 12 existing `notification_event` rows
- WHEN the `is_mandatory` migration runs
- THEN every row resolves `is_mandatory = false` with no manual backfill required

### Requirement: `resolve()` is mandatory-aware

`NotificationSettingService::resolve()` MUST return `is_active = true` whenever the resolved event's `is_mandatory` is `true`, regardless of what a stored `NotificationSetting.is_active` says. For a non-mandatory event, `resolve()` behavior is unchanged.

#### Scenario: Stored setting cannot disable a mandatory event

- GIVEN a mandatory event has a stored tenant `NotificationSetting.is_active = false`
- WHEN `resolve($key)` is called
- THEN the returned array has `is_active = true`

#### Scenario: Non-mandatory events are unaffected

- GIVEN a non-mandatory event has a stored `NotificationSetting.is_active = false`
- WHEN `resolve($key)` is called
- THEN the returned array has `is_active = false`, unchanged from current behavior

### Requirement: `allForTenant()` payload carries `is_mandatory`

`allForTenant()` MUST include an `is_mandatory` boolean on every event object in its payload. For a mandatory event, `allForTenant()` MUST report `is_active = true` regardless of the stored setting.

#### Scenario: GET /config/notifications includes the flag

- GIVEN a tenant with a mix of stored settings
- WHEN `GET /config/notifications` is called
- THEN every event object in the response includes `is_mandatory`, and any mandatory event reports `is_active: true`

### Requirement: `updateForTenant()` enforces server-side

`updateForTenant()` MUST persist `is_active = true` for a mandatory event regardless of the `is_active` value received in the request body. Channel toggles (`channel_email`, `channel_push`, `channel_whatsapp`) and `board_role_ids` (board-audience events) MUST remain independently updatable for mandatory events.

#### Scenario: Client cannot disable a mandatory event

- GIVEN `PUT /config/notifications` sends `is_active: false` for a mandatory event
- WHEN the request is processed
- THEN the stored `NotificationSetting.is_active` for that event is `true`

#### Scenario: Channels stay editable on a mandatory event

- GIVEN the same request also sends `channel_email: false`
- WHEN the request is processed
- THEN the stored `channel_email` becomes `false` while `is_active` remains `true`

### Requirement: No forced behavior for current tenants

All 12 currently seeded events MUST remain `is_mandatory = false` after this change. The enforcement logic MUST NOT alter observable behavior for any of the 12 existing events beyond the `is_mandatory` mechanism itself.

#### Scenario: Existing tenant config is unchanged

- GIVEN a tenant with existing `notification_setting` rows for all 12 events
- WHEN this change is deployed
- THEN `GET /config/notifications` returns the same `is_active`/channel values as before, plus `is_mandatory: false` on every event
