---
name: copy-variant-template-key
description: notifyEvent() gained a $templateKey param so ONE event can render several copy variants — pattern + the leaked-{token} bug class it fixes
metadata:
  type: project
---

## `notifyEvent($templateKey:)` — one event, several copy variants (2026-07-25)

`NotifiesResidents::notifyEvent()` now takes an optional 8th arg `?string $templateKey = null`
(defaults to `$eventKey`). It selects BOTH `notification_template.{templateKey}` AND
`$data['email_template']` (so the email blade follows the COPY, not the event). The
`notification_event` key still governs configuration: `is_active`, channels, audience,
board roles. There is NO new catalog row.

Use it when the same event fires with a materially different SHAPE of context — not to
add per-tenant variety (that is what the templates screen is for).

First (only) consumer: `ChargeGenerationService::notifyConsolidatedChargesIssued()`
→ event `charge_created`, template `charge_created_batch`.

### Why not a new NotificationEvent

A second event would surface in the admin config UI as a near-duplicate of "Nuevo cargo
emitido" that admins could desync from it (email on for one, off for the other), and it
would need a central UUID const + `CatalogSeeder` + `TenantNotificationSettingSeeder` +
a settings backfill migration. The consolidated dispatch is the SAME event with plural
wording, so only the copy varies.

## Bug class this fixes: leaked `{token}` from a shape-mismatched template

`TemplateRenderer::substitute()` leaves unknown `{tokens}` INTACT on purpose. So any
caller that reuses a template whose declared tokens its context cannot fill ships a
literal `{due_date}` to residents over in-app + push (+ email). Silent — nothing logs.

The catch-up path reused the singular `notification_template.charge_created`
(`"...Vence el {due_date}."`) for a run covering N periods. Result in a real tenant:
`Se generó un nuevo cargo: Mantenimiento. ... Vence el {due_date}.` — wrong token AND
wrong grammatical number.

**Test rule that catches it:** assert the RENDERED `Notification.title`/`message`, with
`assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', ...)`. A `NotificationUser::count()`
assert proves cardinality only and stayed green through the whole defect. See
`Modules/Collections/tests/Feature/Commands/GenerateMonthlyChargesCommandTest.php`
(`test_consolidated_notification_fires_once_per_unit_for_a_multiperiod_catchup`).
Verified by reverting the fix: the regex assert fails, the count assert does not.

`seedTenant()` (WithTenantSetup) DOES run `NotificationTemplateSeeder` via
`TenantDatabaseSeeder`, so template rows exist in feature tests — the template path is
what runs, not the fallback. Keep the hardcoded fallback strings in sync with the seeded
template anyway (they are all that a pre-migration tenant gets).

## Backfilling a NEW template slug to existing tenants

`NotificationTemplateSeeder::run()` is `updateOrCreate` across ALL slugs — calling it
from a migration would WIPE every template a cluster admin customized. Backfill the one
new slug with `Setting::firstOrCreate` inside `Tenant::all()->each(fn ($t) => $t->run(...))`.
Precedent: `Modules/Configuration/database/migrations/2026_07_25_000001_seed_charge_created_batch_notification_template.php`
(tenant-loop shape borrowed from the amenity one, see [[amenity-email-qa-2026-07-19]]).
Verified on the dev DB: 19 tenants → 19 rows, 19 distinct `tenant_id`, idempotent on re-run.

`NotificationTemplatesSection.tsx` lists whatever `notification_template.%` Setting rows
exist, so a new slug appears in the admin UI automatically — no frontend change.

## Related gap noticed, NOT fixed

`charge_created` has `supports_email = true` but there is NO `charge-created.blade.php`
in `Modules/Email/resources/views/`. A cluster that enables email for it gets the silent
plain-text fallback. Email is off by default (`default_channels` = `log` + `firebase_push`),
so this is latent. See [[email-template-contract]].
