# Proposal: Amenity Reservation Notification Emails

> Follow-up to the built `amenity-reservations` module. Enables the EMAIL channel
> for its 5 notification events and ships their designed templates. Input:
> `openspec/changes/amenity-notification-emails/explore.md` (exploration by
> notifications-expert, 2026-07-19). Autonomous session — the decisions below are
> LOCKED by the orchestrator; formalize, do not redesign.

## Intent

The 5 AmenityReservations notification events fire in-app and push today but have
**no email option**. Email is disabled deliberately: all `amenity_reservation.*`
events are seeded with `supports_email=false` because their event keys carry a
**dot** (`amenity_reservation.created`), and `BladeEmailChannel` only hyphenates
underscores — the dot survives into the Blade view name, Laravel's resolver reads
it as a directory separator, the view never resolves, and the channel silently
falls back to plain text.

The goal: give admins the email toggle for these 5 events, ship well-designed
templates, turn email on so it actually works, and prove the full flow renders
and delivers against Mailpit end-to-end (not just fake-dispatched).

The 5 events (all verified wired and firing after their domain transaction):

| UI name (ES) | Event key | Audience |
|---|---|---|
| Nueva reservación de amenidad | `amenity_reservation.created` | board (President + Treasurer picker ∪ administrator) |
| Reservación aprobada | `amenity_reservation.approved` | resident |
| Pago de reservación confirmado | `amenity_reservation.payment_confirmed` | resident |
| Pago de reservación rechazado | `amenity_reservation.payment_rejected` | resident |
| Cargo adicional a reservación | `amenity_reservation.surcharge_added` | resident |

Success = each event offers an email switch in Configuración → Notificaciones, the
5 templates render real HTML (no plain-text fallback), existing tenants have email
on by default (toggleable off), and a real send lands correctly in Mailpit.

## Locked Decisions (orchestrator, 2026-07-19)

1. **Flatten dots in the email slug.** `BladeEmailChannel` derives its view name as
   `str_replace(['_', '.'], '-', $slug)` → `email::amenity-reservation-created` →
   flat `amenity-reservation-created.blade.php`. Backward compatible (no existing
   event key contains a dot) and consistent with the flat-only `copy-emails`
   pipeline. Rationale: without this, every amenity email degrades to plain text.
2. **Existing tenants get email ON.** A one-shot data migration sets
   `channel_email=true` on existing `notification_setting` rows for these 5 events.
   New tenants inherit it via seeder defaults (`default_channels` gains
   `'blade_email'`). Rationale: the user wants emails actually working and testable;
   opt-in-only leaves every existing tenant silent. Admins can still toggle off.
3. **Enrich the `created` context.** The board email for `amenity_reservation.created`
   currently carries only `{amenity_name, reservation_date}` — too thin to be
   actionable. Add unit/resident identification so the board can tell whose
   reservation it is. Additive and harmless to the in-app template (which uses only
   amenity/date). Other events' contexts are sufficient per explore §4.
4. **Emails are informative — no CTA/deep-link in v1.** Avoids touching every
   emitter to inject an app URL (`emailBaseUrl` is static-assets-only). Noted as a
   future enhancement.

## Scope

### In Scope

- **Channel fix**: dot-flattening in `BladeEmailChannel` view-slug derivation +
  docblock update.
- **Catalog flip**: `supports_email=true` and `'blade_email'` in `default_channels`
  for the 5 events in `CatalogSeeder`; rewrite the now-stale explanatory comment
  block (`CatalogSeeder.php:597-604`).
- **Existing-tenant migration**: one-shot data migration flipping `channel_email=true`
  on the 5 events' `notification_setting` rows (referencing the 5 `NotificationEvent`
  UUID constants), with a reversible `down()`.
- **Context enrichment**: extend `ReservationService::reservationContext()` (or the
  `created` emitter) with unit/resident identifiers for the board email.
- **5 email templates**: new `frontend/emails/amenity-reservation-*.tsx` (created,
  approved, payment-confirmed, payment-rejected, surcharge-added) built on the shared
  `_components/` (`EmailLayout`, `Title`, `Card`, `ButtonBase`, footer), cribbing
  `payment-rejected.tsx` / `payment-accepted.tsx`. Compiled to Blade via
  `pnpm copy-emails` (scoped git diff — the run regenerates all blades).
- **Tests**: extend `AmenityReservationNotificationWiringTest` (or sibling) with
  `blade_email` dispatch coverage per event, plus a **real-render** assertion
  (`view('email::…')->render()`), because `Mail::fake()` does NOT render Blade — the
  event-key ↔ filename contract can only be caught by real rendering.
- **QA**: seed CatalogSeeder, real send to Mailpit (SMTP 127.0.0.1:1025, UI
  localhost:8025), inspect the 5 rendered emails.

### Out of Scope

- **No frontend app changes.** `NotificationsConfigSection.tsx` is data-driven —
  flipping `supports_email` makes the switch appear with zero UI edits.
- **CTA / deep-link buttons** in the emails (decision 4 — future enhancement).
- **`deposit_confirmed`** and the other 6 `amenity_reservation.*` events not in the
  5-event set — they stay `supports_email=false`.
- **WhatsApp / SMS** channels for these events.
- Any change to the notification engine, dispatcher, or the earmark/payment flow.

## Capabilities

### New Capabilities
None.

### Modified Capabilities
- `amenity-reservations`: adds the email delivery channel to its 5 notification
  events. No `openspec/specs/` baseline exists — delta applies against
  `openspec/changes/amenity-reservations/spec.md`.
- Cross-cutting: `BladeEmailChannel` slug contract (shared by all email-capable
  events) gains dot support — additive, backward compatible.

## Approach

Work inside the existing custom notification engine (`NotifiesResidents` →
`NotificationSettingService` → `NotificationDispatcher` → `DeliverChannelMessageJob`)
and the existing React Email → Blade pipeline. No new Mailable, no Notification
class, no new channel. Three small backend edits (channel slug, catalog flip,
one-shot migration), one additive context enrichment, five new email sources, and
a render-verifying test layer. The catalog is the single source of email
capability; the per-tenant `notification_setting` row is authoritative at runtime,
which is why existing tenants need the one-shot migration and new tenants need the
seeder default.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `backend/app/Notifications/Channels/BladeEmailChannel.php` | Modified | Flatten `.` in view slug + docblock |
| `backend/database/seeders/CatalogSeeder.php` | Modified | `supports_email=true` + `'blade_email'` default for 5 events; rewrite stale comment 597-604 |
| `backend/Modules/Notification/database/migrations/…` (new) | Added | One-shot: `channel_email=true` on existing rows for the 5 events (reversible) |
| `backend/Modules/AmenityReservations/app/Services/ReservationService.php` | Modified | Enrich `created` email context (unit/resident) |
| `backend/Modules/AmenityReservations/tests/Feature/AmenityReservationNotificationWiringTest.php` (+ render test) | Modified/Added | `blade_email` dispatch per event + real-render assertion |
| `frontend/emails/amenity-reservation-*.tsx` (×5) | Added | Designed React Email templates |
| `backend/Modules/Email/resources/views/amenity-reservation-*.blade.php` (×5) | Added (generated) | `pnpm copy-emails` output — scope the diff |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Slug fix breaks the 20+ existing flat keys | Low | `str_replace(['_','.'],'-')` is a superset of `str_replace('_','-')`; no existing key has a dot; covered by render test |
| Event-key ↔ filename mismatch → silent plain-text fallback | Med | Real-render test (`view(...)->render()`) + Mailpit QA; `Mail::fake()` alone cannot catch it |
| One-shot migration overrides an admin's intended off-state | Low | The toggle was never visible before, so no admin ever chose off; default-on is defensible and toggleable |
| `copy-emails` regenerates all blades, polluting the diff | Med | `git checkout` unintended blades after running; scope the commit |
| Context enrichment touches the `created` emitter | Low | Additive keys only; in-app template unaffected (uses amenity/date) |

## Rollback Plan

- Channel slug and catalog flip are revertible edits. The one-shot migration ships
  with a working `down()` (restore `channel_email=false` on the 5 events' rows).
- Admins can disable email per cluster in Configuración → Notificaciones without a
  deploy. Reverting `supports_email=false` in the catalog + re-seed removes the
  toggle entirely.

## Dependencies

- Custom notification engine (`NotifiesResidents`, `NotificationSettingService`,
  `NotificationDispatcher`, `DeliverChannelMessageJob`) — exists.
- React Email → Blade pipeline (`pnpm copy-emails`) — exists.
- Mailpit running (SMTP 127.0.0.1:1025, UI localhost:8025) — running.

## Success Criteria

- [ ] Each of the 5 events shows an email switch in Configuración → Notificaciones.
- [ ] All 5 templates render real HTML — no plain-text fallback (`Log::warning`).
- [ ] Existing tenants have `channel_email=true` on the 5 events after migration.
- [ ] New tenants seed with email on (`default_channels` includes `blade_email`).
- [ ] The `created` board email identifies the unit/resident.
- [ ] `BladeEmailChannel` resolves dotted event keys to flat blade views.
- [ ] Feature tests cover `blade_email` dispatch + real render per event.
- [ ] A real send lands correctly for all 5 in Mailpit.
