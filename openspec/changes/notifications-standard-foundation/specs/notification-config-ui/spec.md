# Notification Config UI Specification

## Purpose

The Notifications config screen (`NotificationsConfigSection`) stays 100% server-driven: no event list or mandatory-key list is hardcoded client-side. Mandatory events render a locked-on master switch with an "Obligatorio" badge; channel toggles stay editable; the save payload can never disable a mandatory event.

## Requirements

### Requirement: `is_mandatory` flows through the type contract

`INotificationEventConfig` (in `ConfigurationResource.ts`) MUST add `is_mandatory: boolean`, populated exclusively from the `GET /config/notifications` response.

#### Scenario: Type contract carries the flag

- GIVEN the backend response for an event includes `is_mandatory: true`
- WHEN the frontend consumes the response
- THEN the corresponding `INotificationEventConfig` entry has `is_mandatory: true` with no client-side override

### Requirement: Mandatory master switch renders locked-on

For every event row, the master `Switch` MUST render `checked={ev.is_mandatory ? true : ev.is_active}` and `disabled={ev.is_mandatory || !canUpdate || isPending}`.

#### Scenario: Mandatory event switch cannot be toggled off

- GIVEN an event row has `is_mandatory: true`
- WHEN the admin clicks the master switch
- THEN the switch remains checked and no local edit for `is_active` is recorded

### Requirement: "Obligatorio" badge is visible only on mandatory rows

A mandatory event row MUST render an "Obligatorio" badge next to its name/description. A non-mandatory row MUST NOT render it.

#### Scenario: Badge renders selectively

- GIVEN one event has `is_mandatory: true` and another has `is_mandatory: false`
- WHEN the section renders
- THEN only the mandatory event's row shows the "Obligatorio" badge

### Requirement: Channel toggles stay editable on mandatory rows

Channel switches (Email, Push) MUST keep their existing enable rule (`!canUpdate || isPending || !ev.is_active`) regardless of `is_mandatory`. Since a mandatory row's `is_active` is always `true`, its channel toggles remain interactive.

#### Scenario: Admin can still change channels on a mandatory event

- GIVEN a mandatory event row is rendered
- WHEN the admin toggles the Email channel switch
- THEN the local edit records the new `channel_email` value while `is_active` stays `true`

### Requirement: Save payload cannot disable a mandatory event

`handleSave()` MUST send `is_active: ev.is_mandatory ? true : ev.is_active` for every event in the `NotificationEventPatch[]` payload.

#### Scenario: Save payload is safe even with stale local state

- GIVEN a mandatory event's local edit overlay somehow holds `is_active: false`
- WHEN `handleSave()` builds the payload
- THEN the payload entry for that event carries `is_active: true`
