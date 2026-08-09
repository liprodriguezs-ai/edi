# Event catalog (CENTRAL `notification_event`)

UUID consts in `NotificationEvent` model; seeded by `CatalogSeeder::seedNotificationEvents()` (upsert —
re-running updates central rows in place). Per-tenant rows by `TenantNotificationSettingSeeder`
(**firstOrCreate** — re-running does NOT update existing rows).

## Core events (first 11)

| key | audience | dispatched by |
|---|---|---|
| `payment_due_reminder` | resident | `SendChargeRemindersCommand` (cron `collections:send-reminders`, daily 07:00) |
| `payment_registered` | board | `PaymentService::submit()` (resident self-captures → board reviews) |
| `payment_accepted` | resident | `PaymentService::validatePayment()` |
| `payment_rejected` | resident | `PaymentService::reject()` (reason in `cancellation_reason` + `reason` context) |
| `payment_registered_direct` | resident | `PaymentService::register()` (treasurer direct capture) |
| `charge_overdue`, `charge_created`, `monthly_statement` | resident | catalog rows; not all wired yet |
| `visitor_at_gate`, `new_board`, `urgent_notice` | board | catalog rows; not all wired yet |

Resident-audience → recipient = `unitBillingUserId(unit)`. Board-audience → configured board roles.
Default per-tenant: `payment_registered` → roles Treasurer + President; `new_board` → President + Secretary.

## Amenity Reservations (S10, discovered 2026-07-19)

11 `amenity_reservation.*` rows (CatalogSeeder.php:596-781, consts NotificationEvent.php:78-108):
created (board; picker President+Treasurer seeded + FIXED 'administrator' Spatie role merged in emitter),
approved / rejected / payment_required / deposit_confirmed / payment_confirmed / payment_rejected (fired
from `AmenityReservationPaymentRejectionObserver` on Payment earmark cancel) / cancelled (2 notifyEvent
calls, 1 shared resolve) / surcharge_added / payment_reminder / expired. Resident events use
`unitResidentUserIds()` (ALL active unit_user, NOT billing owner). All fire post-transaction. In-app
templates `notification_template.amenity_reservation.*` seeded (NotificationTemplateSeeder:74+).

- **DOTTED-KEY EMAIL KNOT**: keys carry a dot; BladeEmailChannel only does `str_replace('_','-')` →
  view `email::amenity-reservation.created` → Laravel treats the dot as a DIRECTORY separator → never
  resolves → silent plaintext. That's WHY all 11 are `supports_email=false` (comment CatalogSeeder:597-604).
  Fix path: flatten `.`→`-` too in BladeEmailChannel (copy-emails rename loop is FLAT-ONLY; nested views dead end).
- `supports_email/push/whatsapp` on notification_event gate ONLY the config UI switch
  (NotificationsConfigSection.tsx:145); `resolve()`/`updateForTenant()` do NOT check them — the
  per-tenant `notification_setting` row is authoritative at runtime. UI fully data-driven.
- Existing-tenant gotcha: flipping catalog defaults/supports does NOT touch existing
  `notification_setting` rows (firstOrCreate); default-on needs a one-shot data migration.
- Also newer: TRIAL_EXPIRED (hidden/board), PROJECT_CONTRIBUTION_REMINDER; events gained
  `is_mandatory`/`is_hidden` (hidden = never shown/configurable; resolve() forces is_active for both).
- Explore report: `openspec/changes/amenity-notification-emails/explore.md`.

## Catalog events NOT cabled via notifyEvent (deferred, Cambio 4) — WHY

- `charge_overdue` / `charge_created`: triggers EXIST (ChargeStatusService::notifyNewlyOverdue per-unit
  count; ChargeGenerationService::notifyBatchActivated per-batch) but fire via `notifyUsers` (bypass
  config gate). NOT cabled because seeded templates are PER-CHARGE (`{concept_name}`/`{amount}`/`{due_date}`)
  while triggers are AGGREGATED → cabling would render blank vars. Needs per-charge emission (Collections).
- `monthly_statement`: NO trigger flow exists. Pending.
- `visitor_at_gate` / `new_board` / `urgent_notice`: catalog audience=board but real triggers are
  resident-facing via `notifyUsers`. Audience mismatch → deferred (don't half-wire).
