# Notification Event Conversion Specification

## Purpose

Converts already-cataloged notification events from ad-hoc dispatch to the config-gated `NotifiesResidents::notifyEvent()` path, so the tenant's Notifications config screen actually controls whether each one fires. No new catalog rows are introduced. **4 events have a live, in-scope emitter and ARE converted** (`charge_created`, `charge_overdue`, `visitor_at_gate`, `new_board`). **2 events have no convertible in-scope emitter and stay documented-only** — `monthly_statement` (no emitter at all) and `urgent_notice` (its only path is the out-of-scope general Announcements broadcast). See design ADR-3.

## Requirements

### Requirement: Config-gated firing for the 4 live events

Each of `charge_created`, `charge_overdue`, `visitor_at_gate`, and `new_board` MUST resolve its per-tenant configuration via `NotificationSettingService::resolve($key)` (directly or via the `notifyEvent()`/`boardRecipientsForEvent()` `$preResolved` parameter) before dispatching, and MUST dispatch exclusively through `notifyEvent()`. When the resolved `is_active` is `false`, the emitter MUST NOT call `notifyEvent()` and MUST NOT create a notification record for that trigger.

#### Scenario: Disabled event does not fire

- GIVEN a tenant has disabled `charge_overdue` in `notification_setting`
- WHEN a charge transitions to overdue
- THEN no notification is dispatched and no notification log row is created for that trigger

#### Scenario: Enabled event fires and reaches the right recipient

- GIVEN `charge_created` is enabled for a tenant
- WHEN a `charge_batch` issues a charge for a unit
- THEN `notifyEvent('charge_created', ...)` is called with the unit's billing-owner user ID among the recipients

### Requirement: Recipient routing by audience

Resident-audience events (`charge_created`, `charge_overdue`, `visitor_at_gate`) MUST route to the unit's billing owner (`unitBillingUserId()`) or resident set (`unitResidentUserIds()`). The board-audience event `new_board` MUST route through `boardRecipientsForEvent()`, honoring the board roles configured in `notification_setting_board_role`.

#### Scenario: Board event honors configured roles

- GIVEN a tenant configured `new_board` to notify only the President role
- WHEN a new board period opens
- THEN only users holding the President board role for that period receive the notification

#### Scenario: Resident event reaches the billing owner

- GIVEN a unit has a designated billing owner distinct from other residents
- WHEN `visitor_at_gate` fires for that unit
- THEN the billing owner's user ID is among the notified recipients

### Requirement: `monthly_statement` and `urgent_notice` stay cataloged-without-conversion

`monthly_statement` and `urgent_notice` MUST remain valid catalog keys. This change MUST NOT introduce any code path, cron, or job that calls `notifyEvent('monthly_statement', ...)` or `notifyEvent('urgent_notice', ...)`. `urgent_notice`'s only existing path is the general Announcements broadcast, which is out of scope for Phase 0 and MUST NOT be reworked here.

#### Scenario: neither event is wired

- GIVEN the codebase after this change
- WHEN searching for callers of `notifyEvent('monthly_statement', ...)` or `notifyEvent('urgent_notice', ...)`
- THEN zero call sites exist

### Requirement: Emission semantics preserved

Each conversion MUST preserve the existing post-commit dispatch behavior (`DB::afterCommit`) and the existing swallow-and-report failure handling already implemented by `notifyEvent()`. No emitter may let a notification failure roll back or interrupt the triggering business operation.

#### Scenario: Notification failure does not break the business operation

- GIVEN the notification dispatcher throws while handling `visitor_at_gate`
- WHEN a visitor is registered at the gate
- THEN the visitor/access-log record is still created successfully and the exception is only reported, not propagated to the caller

### Requirement: An event with no stored setting resolves active

For any of the 4 converted events, a tenant with no `notification_setting` row yet MUST have `resolve()` return `is_active = true` (deploy-safety default), so conversion does not silently suppress notifications for tenants that have never touched the config screen.

#### Scenario: New tenant receives converted events by default

- GIVEN a newly created tenant with no `notification_setting` rows
- WHEN `charge_created` fires for that tenant
- THEN the notification is dispatched using the event's `default_channels`
