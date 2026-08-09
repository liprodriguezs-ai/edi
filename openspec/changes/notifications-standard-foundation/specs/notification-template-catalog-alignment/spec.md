# Notification Template Catalog Alignment Specification

## Purpose

Reconciles `NotificationTemplateSeeder` default templates 1:1 with the 12 `notification_event` catalog rows: no orphan template keys, and every catalog event has a default template.

## Requirements

### Requirement: No orphan template keys

`NotificationTemplateSeeder` MUST NOT seed a default template for a key with no matching `notification_event.key` row. `charge_reminder` and `welcome_user` MUST be removed unless a repository-wide search finds a live runtime consumer that resolves either key by name (verify before removing).

#### Scenario: Orphan keys are removed

- GIVEN `charge_reminder` and `welcome_user` have no matching catalog event and no runtime consumer
- WHEN `NotificationTemplateSeeder` runs after this change
- THEN neither `notification_template.charge_reminder` nor `notification_template.welcome_user` is seeded

### Requirement: Every catalog event has a default template

For each of the 12 `notification_event` keys, `NotificationTemplateSeeder` MUST seed exactly one default template (title + body), including `trial_expired`, which currently has no default.

#### Scenario: trial_expired resolves to a non-empty default

- GIVEN a tenant with no custom override for `trial_expired`
- WHEN the template is resolved for that event
- THEN `notification_template.trial_expired` returns a non-empty default title and body

### Requirement: Re-seeding is idempotent and non-destructive

Re-running `NotificationTemplateSeeder` MUST NOT duplicate rows or overwrite a tenant's already-customized template; only the default fallback set changes.

#### Scenario: Tenant customization survives re-seed

- GIVEN a tenant has customized the `payment_accepted` template body
- WHEN `NotificationTemplateSeeder` is re-run
- THEN the tenant's customized body is unchanged
