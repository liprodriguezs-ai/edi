# Design — amenity-notification-emails

> Technical design for enabling the EMAIL channel on the 5 in-scope
> `amenity_reservation.*` notification events and shipping their React Email → Blade
> templates, verified E2E against Mailpit. Formalizes the LOCKED decisions in
> `proposal.md`; grounded in `explore.md` (all paths verified on disk).
> Authority skills: `.agents/skills/laravel-backend-architecture/SKILL.md`, `.agents/skills/react-frontend-architecture/SKILL.md`.
> Hard rules honored: CLAUDE.md strict rule 10 (React Email → Blade pipeline, never
> hand-write the `.blade.php`), English identifiers, Spanish (Mexican, tú) email copy.

## 1. Architecture Overview

No new architectural components. The change threads through the **existing** custom
notification engine and the **existing** React Email → Blade pipeline. There is no new
Mailable, no Laravel `Notification` class, no new channel, no new route, no frontend
app change.

```
ReservationService / AmenitySurchargeService / PaymentRejectionObserver
        │  notifyEvent(eventKey, recipients, context, title, body, extra)
        ▼
NotifiesResidents::notifyEvent()   ── sets $data['email_template']=eventKey,
        │                              $data['email_context']=context
        ▼
NotificationSettingService::resolve()  ── per-tenant notification_setting row →
        │                                  channelsFromSetting(): channel_email=true → 'blade_email'
        ▼
NotificationDispatcher → DeliverChannelMessageJob (queued per recipient)
        ▼
BladeEmailChannel::send() → renderContent()
        │  view('email::'.slug($eventKey))  ← slug must FLATTEN the dot
        ▼
Mail::html( view('email::amenity-reservation-created', $context)->render() )
```

The **catalog** (`notification_event`) is the single source of email *capability*
(`supports_email` + `default_channels`); the **per-tenant** `notification_setting` row
is authoritative at *runtime* (`channel_email`). This split is why the change needs
three coordinated writes: (a) the channel slug fix so a dotted key resolves a flat
view, (b) the catalog flip so the UI switch appears and new tenants seed email-on, and
(c) the one-shot migration so existing tenants get `channel_email=true`.

### Layering / boundaries

| Concern | Owner | Touched? |
|---|---|---|
| Email view-slug derivation (cross-cutting, all events) | `BladeEmailChannel` | Yes — dot-flatten |
| Email *capability* per event | `CatalogSeeder` (central `notification_event`) | Yes — 5 rows |
| Email *enablement* per tenant | `notification_setting` rows | Yes — one-shot migration (existing) + seeder default (new) |
| Email *context* (payload) | the emitters | Yes — `created` only (additive) |
| Email *presentation* | `frontend/emails/*.tsx` → generated Blade | Yes — 5 new templates |
| Admin toggle UI | `NotificationsConfigSection.tsx` | No — data-driven, zero edits |

## 2. Decision Records (ADR-style)

### ADR-1 — Flatten dots in the email view slug

- **Decision**: change `BladeEmailChannel::renderContent()` slug derivation from
  `str_replace('_', '-', $templateSlug)` to `str_replace(['_', '.'], '-', $templateSlug)`.
- **Context**: event keys for this module carry a dot (`amenity_reservation.created`).
  `str_replace('_','-')` leaves the dot, producing view `email::amenity-reservation.created`,
  which Laravel's resolver reads as `amenity-reservation/created.blade.php` (a nested path
  the flat-only `copy-emails` loop never produces). The view never resolves → silent
  plain-text fallback. Without this fix EVERY amenity email degrades to plain text.
- **Rationale / backward-compat**: `str_replace(['_','.'],'-')` is a strict superset of the
  old transform for the 20+ existing keys — none contains a dot, so their slug is
  unchanged. Consistent with the flat-only pipeline. Cheapest possible fix (one array
  literal), lives at the one chokepoint every email passes through.
- **Rejected alternatives**:
  - *Ship nested view dirs (`amenity-reservation/created.blade.php`)* — rejected: the
    `copy-emails` rename loop is flat-only (`for f in .../*.html`), so nested paths are a
    dead end and would fork the pipeline.
  - *Rename event keys to drop the dot* — rejected: the dot-namespaced keys are used
    across the catalog, seeders, `notification_template` rows, dispatch sites, and the
    board-role picker; renaming is a large, risky, cross-cutting rename for zero user
    value.
  - *Per-event `email_template` override in the emitter* — rejected: pushes a naming
    concern into every emitter; the channel is the correct single place.

### ADR-2 — Add a `Log::warning` observability note on fallback (KEEP existing behavior)

- **Decision**: the plain-text fallback in `renderContent()` ALREADY emits
  `Log::warning('[NotificationChannel:blade_email] Template view not found …', ['view' => …])`
  (BladeEmailChannel.php:81-83). **No new log needed** — it already exists. The design
  requirement "a real send renders real HTML, no plain-text fallback" is therefore
  *observable*: if a filename ↔ event-key mismatch ships, the log fires with the exact
  missing view name. The design KEEPS this line as-is and treats its ABSENCE during QA as
  the pass signal.
- **Rationale**: the orchestrator asked whether to add a fallback log warning — it is
  already present and sufficient. Adding a second one would be noise. The observability
  win is realized by *asserting on it* in tests (§6) and *watching for it* in QA (§7),
  not by adding code.
- **Note**: keep the docblock of `renderContent()` accurate after the slug change (it
  currently says "'payment_accepted' → 'email::payment-accepted'"; extend the example to
  mention dot-flattening: "'amenity_reservation.created' → 'email::amenity-reservation-created'").

### ADR-3 — Existing tenants default email ON via a one-shot data migration

- **Decision**: ship a one-shot, reversible data migration that sets `channel_email=true`
  on `notification_setting` rows whose `notification_event_id` is one of the 5 in-scope
  UUID constants. New tenants inherit email-on via `default_channels` gaining
  `'blade_email'` in `CatalogSeeder`.
- **Context**: `TenantNotificationSettingSeeder` uses `firstOrCreate` — re-seeding does
  NOT update existing rows. Flipping `supports_email` alone only makes the UI switch
  *appear* (off). Opt-in-only leaves every existing tenant silent, contradicting the
  user's goal of "emails actually working and testable".
- **Rationale**: the toggle was never visible before, so no admin ever chose "off" — a
  default-on flip overrides no deliberate admin choice. Admins can toggle off per cluster
  post-deploy without a code change. Reversible `down()` restores `channel_email=false`.
- **Rejected alternative**: *leave off, admins opt in* — rejected by the orchestrator
  (decision 2): zero-friction testability and the "prove it delivers E2E" success
  criterion require existing tenants to be on by default.

- **QA-correction addendum (2026-07-19)**: Phase-4 E2E testing found that a plain
  `UPDATE` is a no-op for tenants created *before* the AmenityReservations module
  (S10) — they have zero `notification_setting` rows for any `amenity_reservation.*`
  event, so the flip silently affected nothing and their President/Treasurer never
  received the `created` email. The migration's `up()` now first runs the existing,
  idempotent `TenantNotificationSettingSeeder` inside every tenant's context to
  backfill any missing rows (including the default board-role recipients for
  `created`), then applies the explicit `channel_email=true` flip from step 2 above.
  This is a **deliberate, accepted decision** of the QA-correction batch — the first
  migration in this repo to invoke a tenant seeder from inside a migration. **Trade-off**:
  couples this migration to `TenantNotificationSettingSeeder`'s evolving behavior;
  mitigated by keeping the `channel_email` flip explicit and unconditional in step 2
  rather than trusting the seeder's `default_channels` lookup (which may not have the
  catalog's `blade_email` addition yet at migrate time on an old database). **Residual
  risk, accepted**: any future change to the board-role backfill inside
  `TenantNotificationSettingSeeder` is inherited by this migration's behavior on
  existing tenants.

### ADR-4 — Enrich only the `created` context; other events unchanged

- **Decision**: add `unit_label` (unit `full_identifier` accessor) and `resident_name`
  (the reservation creator's `user->name`) to the `amenity_reservation.created` email
  context. All other events' contexts are sufficient (explore §4).
- **Rationale**: the board email answers "whose reservation is this?" — amenity + date
  alone is not actionable for a board reader. Additive keys only; the in-app/push template
  for `created` uses only `{amenity_name}`/`{reservation_date}` and is unaffected.
- **Scope guard**: enrichment is confined to `notifyReservationCreated()` (or
  `reservationContext()` with the two keys added only where safe). Do NOT thread a unit
  label into the resident events — they already read naturally ("Tu reservación de …").

### ADR-5 — Informative emails, no CTA/deep-link in v1

- **Decision**: none of the 5 templates render a "Ver reservación" button. `ButtonBase`
  is NOT used.
- **Rationale**: `emailBaseUrl` injected by the channel is the **static-assets** base, not
  the app URL. A working CTA would require every emitter to inject
  `config('app.frontend_url').'/…'` into context (the Announcements precedent). That
  touches all 3 emitters for cosmetic value; deferred as a future enhancement (proposal
  decision 4). Templates are self-contained informational notices.

## 3. Component & Data-Flow Design (per work item)

### 3.1 `BladeEmailChannel` slug fix

- **File**: `backend/app/Notifications/Channels/BladeEmailChannel.php`
- **Method**: `renderContent()` — line 70:
  - FROM: `$viewName = 'email::'.str_replace('_', '-', $templateSlug);`
  - TO:   `$viewName = 'email::'.str_replace(['_', '.'], '-', $templateSlug);`
- **Docblock**: update the class docblock (lines 9-24) and the `renderContent()` docblock
  (lines 54-63) to note dots are flattened too, with the amenity example.
- **Missing-view behavior**: UNCHANGED — `view()->exists()` guard → `Log::warning` +
  `nl2br(e($body))` plain-text fallback (lines 72-87). This is deliberately preserved for
  plain-text callers; it is not an error path.
- **Backward-compat proof**: for every existing flat key (e.g. `payment_accepted`), the
  input contains no dot, so `str_replace(['_','.'],'-')` produces the identical slug as
  before. Covered by a unit-level render test (§6).

### 3.2 `CatalogSeeder` catalog flip

- **File**: `backend/database/seeders/CatalogSeeder.php`
- **Rows** (the 5 in-scope events, by UUID const / key):
  - `AMENITY_RESERVATION_CREATED` / `amenity_reservation.created` (lines 605-620)
  - `AMENITY_RESERVATION_APPROVED` / `amenity_reservation.approved` (621-636)
  - `AMENITY_RESERVATION_PAYMENT_CONFIRMED` / `amenity_reservation.payment_confirmed` (685-700)
  - `AMENITY_RESERVATION_PAYMENT_REJECTED` / `amenity_reservation.payment_rejected` (701-716)
  - `AMENITY_RESERVATION_SURCHARGE_ADDED` / `amenity_reservation.surcharge_added` (733-748)
- **Per row**: `'supports_email' => false` → `true`; `'default_channels' => json_encode(['log', 'firebase_push'])`
  → `json_encode(['log', 'firebase_push', 'blade_email'])`.
- **Explicitly UNCHANGED** (stay `supports_email=false`): `rejected`, `payment_required`,
  `deposit_confirmed`, `cancelled`, `payment_reminder`, `expired` — the other 6 of the 11
  amenity events (out of scope).
- **Comment rewrite** (lines 596-604): replace the stale block that explains "email is off
  because of the dot" with the new reality. Proposed text:
  ```php
  // ── Amenity Reservations (S10) ────────────────────────────────────
  // 5 of these 11 events support EMAIL (created, approved, payment_confirmed,
  // payment_rejected, surcharge_added): BladeEmailChannel flattens the dotted
  // event key into a flat Blade view slug (str_replace(['_','.'],'-') →
  // email::amenity-reservation-created), and the 5 templates ship under
  // frontend/emails/amenity-reservation-*.tsx. The remaining 6 stay
  // supports_email=false — no template shipped for them.
  ```
- **Upsert semantics**: `seedNotificationEvents()` writes via `upsertCatalog()` (upsert on
  `id`). Re-running `php artisan db:seed --class=CatalogSeeder` UPDATES the 5 central rows
  in place — `supports_email`/`default_channels` are corrected on existing databases. This
  is central-catalog only; it does NOT touch per-tenant `notification_setting` rows (that
  is what the one-shot migration is for).

### 3.3 One-shot data migration (existing-tenant enablement)

- **Location**: `backend/Modules/Notification/database/migrations/2026_07_19_000001_enable_amenity_reservation_emails_on_existing_settings.php`
  (module owns `notification_setting`; timestamp after the latest existing migration
  `2026_07_05_000003`; single-DB — runs in the one `php artisan migrate` pass).
- **Pattern**: mirrors the existing data migration
  `2026_07_05_000003_purge_dead_notification_events_and_hide_trial_expired.php` — plain
  `DB::table(...)->update(...)`, no schema change, so the `->comment()` rule does NOT apply
  (that rule is for schema columns; this is a data migration).
- **`up()`**:
  ```php
  DB::table('notification_setting')
      ->whereIn('notification_event_id', [
          NotificationEvent::AMENITY_RESERVATION_CREATED,
          NotificationEvent::AMENITY_RESERVATION_APPROVED,
          NotificationEvent::AMENITY_RESERVATION_PAYMENT_CONFIRMED,
          NotificationEvent::AMENITY_RESERVATION_PAYMENT_REJECTED,
          NotificationEvent::AMENITY_RESERVATION_SURCHARGE_ADDED,
      ])
      ->update(['channel_email' => true]);
  ```
  Reference the 5 `NotificationEvent::…` UUID constants (import the model) — never
  hard-coded literals — for a single source of truth.
- **`down()`**: same `whereIn`, `->update(['channel_email' => false])`. Reversible and
  symmetric; the docblock notes it restores the pre-change off-state.
- **Idempotency**: `update()` on a boolean column is naturally idempotent — re-running sets
  the same value; no `INSERT`, no uniqueness risk, no dependency on row existence (a tenant
  with no such rows yet is a harmless zero-row update; new tenants seed via `default_channels`).
- **Docblock**: explain WHAT (flip `channel_email=true` for the 5 amenity email events on
  existing tenants), WHY (`TenantNotificationSettingSeeder` is `firstOrCreate` — pre-existing
  rows won't pick up the new default; the UI toggle was never visible so no admin off-choice
  is overridden), and that new tenants are covered by the seeder default, not this migration.

### 3.4 Context enrichment for `amenity_reservation.created`

- **File**: `backend/Modules/AmenityReservations/app/Services/ReservationService.php`
- **Emitter**: `notifyReservationCreated()` (line 515). Enrich there (NOT in the shared
  `reservationContext()` at line 707, which the resident events also use — keep those thin
  to avoid baking unit/resident data into copy that doesn't need it).
- **Added context keys** (both additive, string/nullable):
  - `unit_label` = the unit's `full_identifier` accessor (confirmed on `Unit`, appended;
    `Unit::find($reservation->unit_id)?->full_identifier ?? ''` or via
    `$reservation->unit?->full_identifier`).
  - `resident_name` = the creator's name: `User::find($reservation->user_id)?->name ?? ''`
    (`amenity_reservation.user_id` is the creating resident, set at ReservationService.php:137).
- **Guarding**: resolve defensively (null-safe, empty-string fallback) so the email never
  renders a raw placeholder or throws. The Mailable-less flow passes context straight to the
  Blade — a missing key would render an empty `{{ $unit_label }}`, so the emitter MUST always
  supply both keys (non-null) per the CLAUDE.md rule-10 note ("the Mailable must pass
  non-null values"). Here the emitter is the analog of the Mailable.
- **In-app/push safety**: the `created` in-app template
  (`NotificationTemplateSeeder.amenity_reservation.created`) references only
  `{amenity_name}`/`{reservation_date}`; extra context keys are ignored by the text renderer.
  No regression.

### 3.5 The 5 email templates

- **Files** (flat, filename = flattened event key — the §3.1 contract):
  - `frontend/emails/amenity-reservation-created.tsx`
  - `frontend/emails/amenity-reservation-approved.tsx`
  - `frontend/emails/amenity-reservation-payment-confirmed.tsx`
  - `frontend/emails/amenity-reservation-payment-rejected.tsx`
  - `frontend/emails/amenity-reservation-surcharge-added.tsx`
- **Shared components**: build on `frontend/emails/_components/` — `EmailLayout`
  (wraps header/footer, `previewText`), `Title`/`Text` sections, `Card` for detail blocks,
  `Blade`/`BladeTrans` from `LaravelBlade`, `emailTheme`. **`ButtonBase` NOT used** (ADR-5,
  no CTA). Crib structure from `payment-rejected.tsx` (badge + amount + reason + unit row)
  and `payment-accepted.tsx` (amount confirmation), minus the CTA section.
- **Money**: amounts arrive **already formatted as strings** (`TenantMoney::format`, e.g.
  `"$1,500.00"`) in context — templates interpolate `<Blade variable="amount" />` verbatim.
  **No minor-unit math, no currency assembly in the template.** (Verified explore §4:
  `formatMoney()`/`TenantMoney::format` runs in the service; the `$2,400.00 MXN` in the
  `payment-rejected.tsx` fallback is demo `PreviewProps`, not template logic.)
- **Preheader gotcha**: keep `previewText` GENERIC (a fixed Spanish string, no interpolated
  PreviewProps) — interpolating bakes fallback demo data into the compiled Blade.
- **Blade variable names**: FLAT snake_case matching the emitter context keys exactly
  (`<Blade variable="amenity_name" />`, `reservation_date`, `amount`, `reason`,
  `concept_name`, `description`, `amount_paid`, `unit_label`, `resident_name`).

Per-event content design (subject = the rendered notification title, injected by the
channel from the emitter's `title`/`notification_template`, NOT set in the tsx):

| Template | Subject (from title) | previewText (generic) | Title (h) | Body vars | Detail block |
|---|---|---|---|---|---|
| `created` | "Nueva reservación de amenidad" | "Nueva reservación de amenidad en tu clúster" | "Nueva reservación" | `resident_name`, `unit_label`, `amenity_name`, `reservation_date` | Card: unidad = `unit_label`, residente = `resident_name`, amenidad = `amenity_name`, fecha = `reservation_date`. Neutral/informational (board-facing) tone. |
| `approved` | "Reservación aprobada" | "Tu reservación de amenidad fue aprobada" | "Reservación aprobada" | `amenity_name`, `reservation_date` | Success badge (green ✓), Card: amenidad + fecha. "Tu reservación de {amenity_name} para el {reservation_date} fue aprobada." |
| `payment-confirmed` | "Pago de reservación confirmado" (title "Reservación liquidada") | "Tu pago de reservación fue confirmado" | "Reservación liquidada" | `amenity_name`, `reservation_date`, `amount_paid`, `amount_outstanding` | Success badge, amount block = `amount_paid`, Card amenidad/fecha. Note: on full settle `amount_outstanding` is the formatted $0.00 — copy states "completamente liquidada". |
| `payment-rejected` | "Pago de reservación rechazado" | "Tu pago de reservación no pudo validarse" | "Pago no validado" | `amenity_name`, `reservation_date`, `amount`, `reason` | Rejection badge (red ✗), amount block = `amount`, highlighted `reason` block, Card amenidad/fecha. Closest crib = `payment-rejected.tsx` (drop the CTA). |
| `surcharge-added` | "Cargo adicional a reservación" (title "Cargo adicional aplicado") | "Se aplicó un cargo adicional a tu reservación" | "Cargo adicional aplicado" | `amenity_name`, `reservation_date`, `amount`, `concept_name`, `description` | Warning/amount block = `amount`, Card: concepto = `concept_name`, amenidad/fecha; `description` in a note block (guard empty). |

- **`PreviewProps`**: each template ends with realistic Mexican-Spanish demo `PreviewProps`
  (never interpolated into `previewText`) for local `pnpm dev` preview only.
- **Copy voice**: Mexican Spanish, "tú", no voseo (per `spanish-dialect-mexican` memory).

### 3.6 `copy-emails` scoped workflow

1. From `frontend/`: `pnpm copy-emails` (runs `email export` with `BLADE_OUTPUT=true`,
   copies HTML → `backend/Modules/Email/resources/views/*.blade.php`, static assets →
   `backend/public/email/static/`, renames top-level `*.html` → `*.blade.php`).
2. The run **regenerates ALL blades**. Immediately `git status` and
   `git checkout -- <blade>` every regenerated `backend/Modules/Email/resources/views/*.blade.php`
   that is NOT one of the 5 new `amenity-reservation-*.blade.php`, plus any regenerated
   `backend/public/email/static/*` you did not intend — keep the diff scoped to the 5 new
   files (+ any genuinely new static asset the templates introduce).
3. Verify the 5 generated blades exist with the exact flat names matching the slug contract:
   `amenity-reservation-created.blade.php`, `-approved`, `-payment-confirmed`,
   `-payment-rejected`, `-surcharge-added`.
4. NEVER hand-edit the generated `.blade.php` (CLAUDE.md strict rule 10) — edit the `.tsx`
   and recompile.

## 4. Test Design

Location: extend `backend/Modules/AmenityReservations/tests/Feature/AmenityReservationNotificationWiringTest.php`
(or add a sibling `AmenityReservationEmailRenderTest.php` for the pure render tests — the
render tests need no HTTP flow, so a focused sibling keeps the wiring suite lean; the
dispatch tests belong in the wiring suite). Host php, host Postgres, ONE process at a time.

### 4.1 Real-render tests (the load-bearing coverage)

`Mail::fake()` does NOT render Blade, so the event-key ↔ filename contract can only be
caught by REAL rendering. Add one test per event that renders the view directly:

```php
$html = view('email::amenity-reservation-created', $representativeContext)->render();
$this->assertStringContainsString('Nueva reservación', $html);   // expected Spanish copy
$this->assertStringNotContainsString('{{', $html);               // no un-substituted placeholder
$this->assertStringNotContainsString('}}', $html);
```

- **Representative contexts per event** (mirror the emitter payloads, explore §4):
  - `created`: `{amenity_name:'Alberca', reservation_date:'25/07/2026', unit_label:'Casa 12 · Torre A', resident_name:'Carlos Mendoza'}`
  - `approved`: `{amenity_name, reservation_date}`
  - `payment_confirmed`: `{amenity_name, reservation_date, amount_paid:'$1,500.00', amount_outstanding:'$0.00'}`
  - `payment_rejected`: `{amenity_name, reservation_date, amount:'$1,500.00', reason:'Comprobante ilegible.'}`
  - `surcharge_added`: `{amenity_name, reservation_date, amount:'$250.00', concept_name:'Daños e incidentes', description:'Vidrio roto.'}`
- **Assertions**: each expected Spanish string appears; NO raw `{{ }}` (proves every context
  var was supplied); optionally assert the interpolated value appears (e.g. `Carlos Mendoza`,
  `$1,500.00`).
- **Slug-flatten unit assertion**: assert `view()->exists('email::amenity-reservation-created')`
  is true (the flattened slug resolves) AND that the channel produces this slug — a small
  test that calls the slug transform (or asserts `BladeEmailChannel` renders HTML, not the
  `nl2br` fallback, for a dotted key). Also assert an EXISTING flat key
  (`view()->exists('email::payment-accepted')`) still resolves (backward-compat).

### 4.2 `blade_email` dispatch extension

Extend the wiring suite with per-event coverage that `blade_email` is enqueued when
`channel_email=true`:

- Set `channel_email=true` on the tenant's `notification_setting` row for the event under
  test (runtime channel list comes from that row, not `NOTIFICATION_CHANNELS` which
  `phpunit.xml` pins to `log`).
- Use `Queue::fake()` + `assertPushed(DeliverChannelMessageJob …)` to assert ENQUEUE of the
  email channel job (per explore §6 queue caveat). To assert the mail EFFECT, a variant
  that does NOT fake the queue + `Mail::fake()` + `Mail::assertSent` — but note `Mail::fake`
  won't render Blade, so the render assertion (§4.1) is the real contract check.
- Cover all 5 in-scope events.

### 4.3 Migration idempotency test

- A feature test that seeds a tenant (so `notification_setting` rows exist with
  `channel_email=false` for the 5 events under the OLD default), runs the migration's `up()`
  logic (or asserts post-`migrate` state), and asserts `channel_email=true` on all 5 rows.
- Run the update twice → assert the value is still `true` (idempotent, no error).
- Assert `down()` restores `channel_email=false` on the 5 rows and leaves other events
  untouched.
- Assert a NON-scope event (e.g. `amenity_reservation.rejected`) is NOT flipped by the
  migration.

### 4.4 Regression guard

- Confirm the enriched `created` context does not break the existing
  `test_create_notifies_board_and_administrator_but_not_the_creating_resident` (in-app title
  assertion unchanged — extra context keys are inert for the text renderer).

## 5. QA-vs-Mailpit Runbook

**Prereqs** (explore §6): Mailpit running — SMTP `127.0.0.1:1025`, UI `http://localhost:8025`.
Backend `.env`: `MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025` (host process,
NOT the docker `mailpit` hostname), a `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME`. Recipients must
have a real email on their `user` row (the channel filters to addresses containing `@`).

**Setup**:
1. `php artisan db:seed --class=CatalogSeeder` (central upsert — flips `supports_email` +
   `default_channels` for the 5 events).
2. Run migrations so the one-shot data migration flips existing tenants' `channel_email=true`
   (or, on a fresh dev DB seeded post-change, the tenant seeder default already includes
   `blade_email`).
3. In Configuración → Notificaciones, confirm the 5 events now show an email switch and it
   is ON (or enable it if verifying an existing tenant that predates the migration).
4. Ensure the target resident/board users have email addresses.

**Trigger each event** (dev app at `http://192.168.100.136:5173/`, API same host):

| Event | How to trigger |
|---|---|
| `created` | As a resident, create a reservation: `POST /api/amenity_reservation {amenity_id, unit_id, reservation_date}` (or the "Reservar" UI). Board recipients (president/treasurer picker ∪ administrator) get the email. |
| `approved` | As board/admin: `POST /api/amenity_reservation/{id}/approve` (or "Aprobar" in the amenity-reservation admin list). Resident gets the email. |
| `payment_confirmed` | Resident submits an earmark payment `POST /api/payment/submit {earmark_type:'amenity_reservation', earmark_id, total_amount = full outstanding, receipt}`, then treasurer `POST /api/payment/{id}/validate`. On full settle, resident gets "Reservación liquidada" email. |
| `payment_rejected` | Same submit, then treasurer `POST /api/payment/{id}/reject {reason}`. Resident gets the rejection email with the reason. |
| `surcharge_added` | As admin on a CONFIRMED reservation: `POST /api/amenity_reservation/{id}/surcharges {charge_concept_id, amount, description}`. Resident gets the surcharge email. |

**Verify in Mailpit**:
- UI: open `http://localhost:8025`, inspect the 5 messages — subject matches the event
  title, HTML renders (styled, not a bare `<p>nl2br…</p>` fallback), Spanish copy correct,
  amounts formatted, no raw `{{ }}`, unit/resident shown on the `created` email.
- API: `GET http://localhost:8025/api/v1/messages` lists messages;
  `GET http://localhost:8025/api/v1/message/{ID}` returns the full HTML for a scripted
  assertion (grep for expected Spanish copy and the ABSENCE of `{{`).
- **Fallback check**: tail the Laravel log — the ABSENCE of
  `[NotificationChannel:blade_email] Template view not found` confirms real HTML rendered
  (its PRESENCE means a filename ↔ slug mismatch; fix the `.tsx` name and re-`copy-emails`).

## 6. Risks & Open Items

| Risk | Severity | Mitigation |
|---|---|---|
| Filename ↔ flattened-event-key mismatch → silent plain-text fallback | High | Real-render tests (§4.1) + Mailpit + `Log::warning` watch (§5). This is the core risk; `Mail::fake()` cannot catch it. |
| `copy-emails` pollutes the diff (regenerates all blades) | Medium | `git checkout` unrelated regenerated blades/static assets after the run (§3.6). |
| One-shot migration overrides an admin's intended off-state | Low | Toggle was never visible pre-change → no deliberate off-choice exists; default-on defensible; reversible `down()`. |
| Enrichment renders empty `unit_label`/`resident_name` if a lookup misses | Low | Emitter supplies non-null (empty-string) fallbacks; render test asserts no raw placeholder. |
| Slug change affects an unforeseen dotted key | Low | No existing event key contains a dot (verified in `NotificationEvent` constants + CatalogSeeder); backward-compat render test on a flat key. |
| Money double-formatting if a template adds " MXN"/math | Low | Design mandates verbatim `<Blade variable>` interpolation; amounts arrive pre-formatted. |

**Open items requiring validation during apply/verify**:
- Confirm the exact `Card`/`Title` component APIs in `frontend/emails/_components/` when
  authoring (only `EmailLayout`, `LaravelBlade`, `_theme` were read in full during design;
  `Card`/`Title` usage should follow `payment-rejected.tsx`/`announcement-published.tsx`).
- Confirm `MAIL_*` dev `.env` values are set for Mailpit before QA (not verifiable from code).
- Decide whether the render tests live in the wiring suite or a sibling file (design
  recommends a sibling `AmenityReservationEmailRenderTest.php` for the pure-render cases).
