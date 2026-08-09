# Tasks — amenity-notification-emails

> Implementation checklist for the SDD change `amenity-notification-emails`.
> Spec: `openspec/changes/amenity-notification-emails/specs/amenity-reservations/spec.md`.
> Design: `openspec/changes/amenity-notification-emails/design.md`.
> Delivery strategy: `exception-ok` (user pre-authorized exceeding the 400-line
> review budget for this change — see Review Workload Forecast at the bottom).
> Test runner: HOST `php artisan test` against host Postgres. Only ONE test
> process at a time (see `backend-tests-run-on-host-php` project convention).

---

## Phase 1 — Backend engine

Four independent files. No task in this phase depends on another — safe to
parallelize across writers, but all four MUST land before Phase 3 (tests) can
pass.

- [x] **1.1 — Flatten dots in the email view-slug** *(parallel-safe)*
  - File: `backend/app/Notifications/Channels/BladeEmailChannel.php`
  - Change `renderContent()` line ~70:
    `$viewName = 'email::'.str_replace('_', '-', $templateSlug);` →
    `$viewName = 'email::'.str_replace(['_', '.'], '-', $templateSlug);`
  - Update the class docblock (lines ~9-24) and the `renderContent()` docblock
    (lines ~54-63) to document dot-flattening, adding the example
    `'amenity_reservation.created' → 'email::amenity-reservation-created'`.
  - Do NOT touch the `view()->exists()` guard / `Log::warning` plain-text
    fallback (lines ~72-87) — behavior stays identical, only the slug
    derivation changes.
  - **Acceptance** (spec: *Email slug flattens dotted event keys*): a dotted
    key resolves to a flat view name; every existing underscore-only key
    produces a byte-identical slug to before.

- [x] **1.2 — Flip catalog email capability for the 5 events** *(parallel-safe)*
  - File: `backend/database/seeders/CatalogSeeder.php`
  - For each of the 5 rows below, set `'supports_email' => true` and add
    `'blade_email'` to `'default_channels'` (`json_encode(['log', 'firebase_push', 'blade_email'])`):
    - `AMENITY_RESERVATION_CREATED` / `amenity_reservation.created` (~lines 605-620)
    - `AMENITY_RESERVATION_APPROVED` / `amenity_reservation.approved` (~lines 621-636)
    - `AMENITY_RESERVATION_PAYMENT_CONFIRMED` / `amenity_reservation.payment_confirmed` (~lines 685-700)
    - `AMENITY_RESERVATION_PAYMENT_REJECTED` / `amenity_reservation.payment_rejected` (~lines 701-716)
    - `AMENITY_RESERVATION_SURCHARGE_ADDED` / `amenity_reservation.surcharge_added` (~lines 733-748)
  - Leave the other 6 `amenity_reservation.*` events (`rejected`,
    `payment_required`, `deposit_confirmed`, `cancelled`, `payment_reminder`,
    `expired`) untouched at `supports_email => false`.
  - Rewrite the stale explanatory comment block (~lines 596-604) per design
    §3.2 — replace the "email is off because of the dot" note with the new
    reality (5-of-11 support email now; the other 6 do not; view-slug
    flattening explained).
  - **Acceptance** (spec: *Central catalog exposes email capability for the 5
    events*): re-running `CatalogSeeder::seedNotificationEvents()` sets
    `supports_email=true` + `default_channels` includes `blade_email` for the
    5 target events only; all other `amenity_reservation.*` rows are
    unaffected.

- [x] **1.3 — One-shot migration: existing tenants get `channel_email=true`** *(parallel-safe)*
  - New file: `backend/Modules/Notification/database/migrations/2026_07_19_000001_enable_amenity_reservation_emails_on_existing_settings.php`
    (timestamp after the latest existing migration `2026_07_05_000003`).
  - Pattern: mirror `2026_07_05_000003_purge_dead_notification_events_and_hide_trial_expired.php`
    — plain `DB::table('notification_setting')->update(...)`, no schema
    change, so the migration `->comment()` column rule does not apply here.
  - `up()`: `whereIn('notification_event_id', [NotificationEvent::AMENITY_RESERVATION_CREATED, ...APPROVED, ...PAYMENT_CONFIRMED, ...PAYMENT_REJECTED, ...SURCHARGE_ADDED])->update(['channel_email' => true])`
    — reference the 5 `NotificationEvent::…` UUID constants, never hard-coded
    literals.
  - `down()`: same `whereIn`, `->update(['channel_email' => false])`.
  - Docblock: explain WHAT (flip `channel_email=true` for the 5 amenity email
    events on existing tenants), WHY (`TenantNotificationSettingSeeder` uses
    `firstOrCreate` — pre-existing rows don't pick up the new seeder default;
    the toggle was never visible before, so no admin off-choice is
    overridden), and that new tenants are covered by the 1.2 seeder default,
    not this migration.
  - **Acceptance** (spec: *Existing tenants receive the email channel via
    one-shot migration*): only the 5 target events' `notification_setting`
    rows flip to `channel_email=true`; re-running the migration is a no-op
    (idempotent, no error); `down()` restores `channel_email=false` on
    exactly those 5 rows.
  - **QA correction (2026-07-19, post Phase-4 E2E)**: the original plain
    `whereIn->update()` is a no-op for tenants created BEFORE the
    AmenityReservations module (S10) — they have ZERO `notification_setting`
    rows for any `amenity_reservation.*` event, so `board_role_ids` resolves
    to `[]` and President/Treasurer are silently dropped from
    `amenity_reservation.created` emails. Fixed by adding a backfill step:
    `up()` now first runs `TenantNotificationSettingSeeder` inside every
    existing tenant's context (idempotent `firstOrCreate`, creates any
    missing amenity rows + the default President/Treasurer board-role
    pivot), THEN applies the original `channel_email=true` flip explicitly
    (not relying on `CatalogSeeder`'s `default_channels`, which may not have
    re-run yet on an old database at migrate time). `down()` is unchanged —
    it deliberately does not delete backfilled rows. Covered by a 4th test
    scenario in `EnableAmenityReservationEmailsMigrationTest`
    (`test_migration_backfills_missing_amenity_settings_for_a_pre_amenity_tenant`).
  - **Review-fixes batch (2026-07-19, tenancy review W1)**: both bulk
    `update()` calls (`up()` and `down()`) now add `->whereNull('deleted_at')`
    so soft-deleted `notification_setting` rows are never flipped — a row
    restored later must not resurrect with `channel_email=true` from this
    migration. File-level fix only; dev DB already ran the migration and has
    no soft-deleted amenity settings rows, so no rollback/re-migrate was
    needed.

- [x] **1.4 — Enrich `created` email context with unit/resident identity** *(parallel-safe)*
  - File: `backend/Modules/AmenityReservations/app/Services/ReservationService.php`
  - In `notifyReservationCreated()` (~line 515) — NOT the shared
    `reservationContext()` (~line 707, also used by resident events) — add:
    - `unit_label` = the unit's `full_identifier` accessor
      (`$reservation->unit?->full_identifier ?? ''`).
    - `resident_name` = the creating resident's name
      (`User::find($reservation->user_id)?->name ?? ''`, since
      `amenity_reservation.user_id` is the creator, set at
      `ReservationService.php:137`).
  - Both keys MUST be non-null (empty-string fallback) — the Mailable-less
    flow passes context straight to Blade; a missing key renders an empty
    `{{ $var }}` rather than throwing.
  - Do NOT thread these keys into the resident-facing events (`approved`,
    `payment_confirmed`, `payment_rejected`, `surcharge_added`) — keep
    `reservationContext()` thin per design ADR-4.
  - **Acceptance** (spec: *Board email context identifies the unit and
    resident*): `created` email context includes `amenity_name`,
    `reservation_date`, `unit_label`, `resident_name`; the in-app notification
    for `created` (which only reads `{amenity_name}`/`{reservation_date}`) is
    unaffected — verify by running the existing
    `test_create_notifies_board_and_administrator_but_not_the_creating_resident`
    test (regression guard, also tracked in task 3.4).
  - **Review-fixes batch (2026-07-19, architecture review W2/W3)**: the inline
    comment above the enrichment previously claimed these two keys go "to the
    email context only, not the in-app template" — false: they ride the SAME
    `$context` that `notifyEvent()` also passes to the in-app
    `TemplateRenderer` (harmless today only because no in-app template
    references `unit_label`/`resident_name`). Comment rewritten to state that
    truth; no structural change to the context split. Also closed a coverage
    gap (W3): the real-render test in `AmenityReservationEmailRenderTest`
    hand-fed `unit_label`/`resident_name` rather than proving they reach the
    email through the actual `ReservationService` production path. Added
    two new assertions against the real path (task 3.4 in Phase 3).

## Phase 2 — Email templates (React Email → Blade)

Blocked by: nothing structurally (templates don't need 1.1-1.4 to be authored),
but the render tests in Phase 3 need both Phase 1 and Phase 2 complete. The 5
`.tsx` files are independent of each other — parallel-safe. The `copy-emails`
compile step (2.6) is sequential and must run AFTER all 5 templates exist.

- [x] **2.1 — `created` template** *(parallel-safe)*
  - New file: `frontend/emails/amenity-reservation-created.tsx`
  - Crib structure from `frontend/emails/payment-rejected.tsx` (badge + Card
    detail block) minus the CTA. Board-facing, neutral/informational tone.
  - Subject comes from the notification title (NOT set in the `.tsx`).
    `previewText` (generic, fixed string): "Nueva reservación de amenidad en
    tu clúster". Title (h): "Nueva reservación".
  - Blade variables (flat snake_case, exact match to context keys):
    `resident_name`, `unit_label`, `amenity_name`, `reservation_date`. Card:
    unidad = `unit_label`, residente = `resident_name`, amenidad =
    `amenity_name`, fecha = `reservation_date`.
  - `ButtonBase` NOT used (ADR-5 — no CTA in v1).
  - Realistic Mexican-Spanish `PreviewProps` at the bottom for local
    `pnpm dev` preview only (never interpolated into `previewText`).

- [x] **2.2 — `approved` template** *(parallel-safe)*
  - New file: `frontend/emails/amenity-reservation-approved.tsx`
  - `previewText`: "Tu reservación de amenidad fue aprobada". Title:
    "Reservación aprobada". Success badge (green ✓).
  - Blade variables: `amenity_name`, `reservation_date`. Body: "Tu
    reservación de {amenity_name} para el {reservation_date} fue aprobada."
    Card: amenidad + fecha.

- [x] **2.3 — `payment-confirmed` template** *(parallel-safe)*
  - New file: `frontend/emails/amenity-reservation-payment-confirmed.tsx`
  - `previewText`: "Tu pago de reservación fue confirmado". Title:
    "Reservación liquidada". Success badge.
  - Blade variables: `amenity_name`, `reservation_date`, `amount_paid`,
    `amount_outstanding`. Amount block = `amount_paid`. Copy states
    "completamente liquidada" (on full settle `amount_outstanding` arrives as
    formatted `$0.00`). Card: amenidad + fecha.
  - Amounts arrive pre-formatted as strings (`TenantMoney::format`, e.g.
    `"$1,500.00"`) — interpolate verbatim via `<Blade variable="amount_paid" />`,
    no math/currency assembly in the template.

- [x] **2.4 — `payment-rejected` template** *(parallel-safe)*
  - New file: `frontend/emails/amenity-reservation-payment-rejected.tsx`
  - `previewText`: "Tu pago de reservación no pudo validarse". Title: "Pago no
    validado". Rejection badge (red ✗).
  - Blade variables: `amenity_name`, `reservation_date`, `amount`, `reason`.
    Amount block = `amount`, highlighted `reason` block, Card amenidad/fecha.
  - Closest crib = `payment-rejected.tsx` — drop the CTA section (ADR-5).

- [x] **2.5 — `surcharge-added` template** *(parallel-safe)*
  - New file: `frontend/emails/amenity-reservation-surcharge-added.tsx`
  - `previewText`: "Se aplicó un cargo adicional a tu reservación". Title:
    "Cargo adicional aplicado".
  - Blade variables: `amenity_name`, `reservation_date`, `amount`,
    `concept_name`, `description`. Warning/amount block = `amount`, Card:
    concepto = `concept_name`, amenidad/fecha; `description` in a note block
    — guard for empty (defensive render, no raw placeholder if absent).

- [x] **2.6 — Compile + scope the diff** *(sequential, after 2.1-2.5)*
  - From `frontend/`: run `pnpm copy-emails`.
  - This regenerates ALL blades. Immediately `git status` and
    `git checkout -- <blade>` every regenerated
    `backend/Modules/Email/resources/views/*.blade.php` that is NOT one of
    the 5 new `amenity-reservation-*.blade.php`, plus any regenerated
    `backend/public/email/static/*` not introduced by these 5 templates.
  - Verify the 5 generated blades exist with the exact flat names matching
    the slug contract from task 1.1: `amenity-reservation-created.blade.php`,
    `-approved`, `-payment-confirmed`, `-payment-rejected`,
    `-surcharge-added`.
  - NEVER hand-edit the generated `.blade.php` files (CLAUDE.md strict rule
    10) — if content is wrong, fix the `.tsx` and re-run this task.
  - **Acceptance** (spec: *Designed templates render real HTML, no
    plain-text fallback*): the 5 flat Blade views exist under
    `backend/Modules/Email/resources/views/` and
    `view('email::{slug}', $context)->render()` succeeds without a
    view-not-found error for each.
  - Note: the 5 generated `.blade.php` files are **generated-artifact**
    output — excluded from the authored-line estimate below, but they are
    real diff content that must be committed alongside the `.tsx` sources.

---

## Phase 3 — Tests

Blocked by: Phase 1 (engine changes) AND Phase 2 (templates compiled) both
complete — render tests need real views on disk, dispatch tests need
`supports_email`/migration behavior in place.

- [x] **3.1 — Real-render tests per event** *(parallel-safe against 3.2/3.3)*
  - New file: `backend/Modules/AmenityReservations/tests/Feature/AmenityReservationEmailRenderTest.php`
  - One test per event calling `view('email::{slug}', $representativeContext)->render()`
    directly (no HTTP flow) and asserting:
    - Expected Spanish copy string(s) appear (e.g. "Nueva reservación",
      "Reservación aprobada", "Reservación liquidada", "Pago no validado",
      "Cargo adicional aplicado").
    - `assertStringNotContainsString('{{', $html)` and `'}}'` — proves every
      context variable was supplied, no raw placeholder leaked.
    - Optionally assert an interpolated value appears verbatim (e.g.
      `'Carlos Mendoza'`, `'$1,500.00'`).
  - Representative contexts per event (mirror design §4.1):
    - `created`: `{amenity_name:'Alberca', reservation_date:'25/07/2026', unit_label:'Casa 12 · Torre A', resident_name:'Carlos Mendoza'}`
    - `approved`: `{amenity_name, reservation_date}`
    - `payment_confirmed`: `{amenity_name, reservation_date, amount_paid:'$1,500.00', amount_outstanding:'$0.00'}`
    - `payment_rejected`: `{amenity_name, reservation_date, amount:'$1,500.00', reason:'Comprobante ilegible.'}`
    - `surcharge_added`: `{amenity_name, reservation_date, amount:'$250.00', concept_name:'Daños e incidentes', description:'Vidrio roto.'}`
  - Add a slug-flatten unit assertion: `view()->exists('email::amenity-reservation-created')`
    is `true`, AND an existing flat key (`view()->exists('email::payment-accepted')`)
    still resolves (backward-compat proof for task 1.1).
  - **Acceptance** (spec: *Feature tests cover dispatch and real rendering
    per event* — render half; *Designed templates render real HTML*): a
    deliberately mismatched filename/slug fails this test with a
    view-not-found error, rather than silently passing under `Mail::fake()`.

- [x] **3.2 — `blade_email` dispatch coverage per event** *(parallel-safe against 3.1/3.3)*
  - File: `backend/Modules/AmenityReservations/tests/Feature/AmenityReservationNotificationWiringTest.php`
    (extend existing).
  - For each of the 5 events: set `channel_email=true` on the tenant's
    `notification_setting` row under test (runtime channel list reads that
    row, NOT `NOTIFICATION_CHANNELS`, which `phpunit.xml` pins to `log`).
  - Use `Queue::fake()` + `assertPushed(DeliverChannelMessageJob::class, ...)`
    to assert the `blade_email` channel job is enqueued.
  - Add one variant per event without faking the queue, using `Mail::fake()`
    + `Mail::assertSent(...)` to assert the mail effect — but note (in a code
    comment) that `Mail::fake()` does NOT render Blade, so 3.1 remains the
    real contract check for the filename/slug.
  - **Acceptance** (spec: *Feature tests cover dispatch and real rendering
    per event* — dispatch half): all 5 events assert `blade_email` dispatch
    when `channel_email=true`.
  - **Review-fixes batch (2026-07-19, architecture review W3)**: closed a
    coverage gap — the real-render tests (3.1) proved templates render
    correctly given *hand-fed* `unit_label`/`resident_name`, but nothing
    proved those keys are actually produced by
    `ReservationService::notifyReservationCreated()` and survive the full
    dispatch path. Added: (1) `test_created_dispatches_blade_email_when_channel_enabled`
    now also inspects the queued `DeliverChannelMessageJob`'s
    `data['email_context']`, asserting `unit_label`/`resident_name` match the
    real unit/resident; (2)
    `test_created_email_lands_in_the_array_mail_transport_with_unit_and_resident_identity`
    — a new effect-level test (mirrors `test_approved_email_lands_in_the_array_mail_transport`)
    that runs the real `created` flow end-to-end (no faking) and asserts the
    rendered HTML in Symfony's array mail transport contains the real unit
    label and resident name.

- [x] **3.3 — Migration idempotency test** *(parallel-safe against 3.1/3.2)*
  - New file: `backend/Modules/Notification/tests/Feature/EnableAmenityReservationEmailsMigrationTest.php`
    (or the nearest existing Notification-module migration test location if
    one already exists — check before creating a new file).
  - Seed a tenant so `notification_setting` rows exist with
    `channel_email=false` for the 5 target events (pre-migration default).
  - Run the migration's `up()` logic (or assert post-`migrate` state) and
    assert `channel_email=true` on all 5 rows.
  - Run the update a second time and assert the value is still `true` (no
    error, idempotent).
  - Assert `down()` restores `channel_email=false` on exactly the 5 rows and
    leaves other events untouched.
  - Assert a non-scope event (e.g. `amenity_reservation.rejected`) is NOT
    flipped by the migration in either direction.
  - **Acceptance** (spec: *Existing tenants receive the email channel via
    one-shot migration* — both scenarios): migration flips only the 5 target
    rows; re-running is a no-op; `down()` is symmetric.

- [x] **3.4 — Regression guard: `created` context enrichment doesn't break
  the in-app assertion** *(depends on 1.4; can run standalone)*
  - Run (do not need to write new code — this is a verification task):
    `backend/Modules/AmenityReservations/tests/Feature/...`
    `test_create_notifies_board_and_administrator_but_not_the_creating_resident`
    (locate exact file via the existing wiring/feature test suite for
    `ReservationService::notifyReservationCreated`).
  - **Acceptance** (spec: *Board email context identifies the unit and
    resident*, scenario "in-app notification for the same event is
    unaffected"): the existing test still passes unmodified after 1.4 — the
    in-app title/body assertions are unaffected by the two new context keys.

- [x] **3.5 — Run full suite once, host php, single process** *(sequential, after 3.1-3.4)*
  - Command: `php artisan test` (host PHP, host Postgres `127.0.0.1:5432`).
  - **Hard constraint**: only ONE `php artisan test` process at a time against
    the shared `testing` DB — `RefreshDatabase` does `migrate:fresh` per
    process; concurrent runs corrupt each other's schema
    (`SQLSTATE 42P01/42703` on `tenant`-related tables). Do not run this in
    parallel with any other test process in the session.
  - **Acceptance**: all tests in `AmenityReservationEmailRenderTest`,
    `AmenityReservationNotificationWiringTest`, the migration idempotency
    test, and the full existing suite (no regressions) pass green.

---

## Phase 4 — QA E2E vs Mailpit

Sequential — each step depends on the prior. Blocked by all of Phase 1-3.

- [x] **4.1 — Verify Mailpit + backend `.env` MAIL_* config BEFORE any send**
  > ✅ 2026-07-19 (orchestrator): `php artisan config:show mail` → default=smtp, host=127.0.0.1, port=1025 (Mailpit up, baseline 0 messages). QA agent set/confirmed real emails on test users. Note: dev `MAIL_FROM_ADDRESS=hello@example.com` (cosmetic, flagged as follow-up).
  - Confirm Mailpit is running: SMTP `127.0.0.1:1025`, UI
    `http://localhost:8025`.
  - Confirm `backend/.env`: `MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`,
    `MAIL_PORT=1025` (host process — NOT a docker `mailpit` hostname), a
    valid `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME` set.
  - Confirm the target resident/board test users have a real email address on
    their `user` row (`BladeEmailChannel` filters recipients to addresses
    containing `@`).
  - **This task MUST complete and pass before 4.2** — do not attempt a send
    against unverified mail config.

- [x] **4.2 — Seed + migrate to activate the 5 events**
  > ✅ 2026-07-19 (orchestrator): CatalogSeeder re-seeded; migration applied, then ROLLED BACK + RE-APPLIED after the corrective backfill batch — tinker-verified: all 10 dev tenants have 11 amenity notification_setting rows, channel_email=true on the 5 in-scope events, President+Treasurer pivot on `created`. Toggle verified INDIRECTLY (data-driven UI: `supports_email=true` in DB + zero diff on NotificationsConfigSection.tsx/ConfigurationResource.ts per sdd-verify); not visually opened in the browser.
  - `php artisan db:seed --class=CatalogSeeder` (central upsert — flips
    `supports_email` + `default_channels` for the 5 events; idempotent).
  - Run migrations so the Phase 1.3 one-shot migration flips existing
    tenants' `channel_email=true` (fresh dev DBs seeded post-change already
    inherit `blade_email` via the tenant seeder default).
  - In Configuración → Notificaciones, confirm the 5 events show an email
    switch and it is ON.
  - **Acceptance** (spec: *Admin config exposes the email toggle without
    frontend changes*): toggle is visible and reflects
    `notification_setting.channel_email` with zero edits to
    `NotificationsConfigSection.tsx` / `ConfigurationResource.ts`.

- [x] **4.3 — Trigger each of the 5 events and verify in Mailpit** *(sequential per event, but events are independent of each other)*
  > ✅ 2026-07-19 (notifications-expert QA, full report in `.claude/agent-memory/notifications-expert/amenity-email-qa-2026-07-19.md`): all 5 events triggered via the real service-layer lifecycle on tenant "Privada Los Encinos" (create→approve→pay/validate→pay/reject→surcharge), queue drained, and verified via Mailpit API: styled HTML (no nl2br fallback), Spanish copy, formatted amounts, no raw `{{ }}`, `created` shows unit_label+resident_name, and zero `Template view not found` log entries for amenity views. Events with `supports_email=false` correctly sent nothing. RESIDUAL (low risk): the pre-S10-tenant board-recipient scenario was verified via DB/tinker + migration test after the backfill fix, not physically re-sent through Mailpit.
  - Dev app at `http://192.168.100.136:5173/`, API at the same host (per
    CLAUDE.md LAN-IP rule — never `localhost` for the authenticated app).
  - `created`: as a resident, create a reservation
    (`POST /api/amenity_reservation {amenity_id, unit_id, reservation_date}`
    or the "Reservar" UI). Board recipients (president/treasurer picker ∪
    administrator) get the email.
  - `approved`: as board/admin, `POST /api/amenity_reservation/{id}/approve`
    (or "Aprobar" in the admin list). Resident gets the email.
  - `payment_confirmed`: resident submits an earmark payment
    (`POST /api/payment/submit {earmark_type:'amenity_reservation', earmark_id, total_amount=full outstanding, receipt}`),
    then treasurer `POST /api/payment/{id}/validate`. On full settle,
    resident gets the "Reservación liquidada" email.
  - `payment_rejected`: same submit, then treasurer
    `POST /api/payment/{id}/reject {reason}`. Resident gets the rejection
    email with the reason.
  - `surcharge_added`: as admin on a CONFIRMED reservation,
    `POST /api/amenity_reservation/{id}/surcharges {charge_concept_id, amount, description}`.
    Resident gets the surcharge email.
  - Verify per event in Mailpit:
    - UI (`http://localhost:8025`): subject matches the event title, HTML
      renders styled (not a bare `<p>nl2br…</p>` fallback), Spanish copy
      correct, amounts formatted, no raw `{{ }}`, unit/resident shown on the
      `created` email.
    - API: `GET http://localhost:8025/api/v1/messages` lists messages;
      `GET http://localhost:8025/api/v1/message/{ID}` for a scripted grep of
      expected Spanish copy and absence of `{{`.
    - Fallback check: tail the Laravel log — absence of
      `[NotificationChannel:blade_email] Template view not found` confirms
      real HTML rendered (its presence means a filename ↔ slug mismatch —
      fix the `.tsx` name and re-run task 2.6).
  - **Acceptance** (spec: *End-to-end delivery verified against Mailpit*):
    all 5 events produce a styled HTML email in Mailpit with correct subject
    and Spanish copy.

---

## Review Workload Forecast

| Metric | Value |
|---|---|
| Estimated authored changed lines (Phase 1: engine) | ~95 (BladeEmailChannel ~8, CatalogSeeder ~30, migration ~45 new, ReservationService ~15) |
| Estimated authored changed lines (Phase 2: templates) | ~550 (5 × `frontend/emails/*.tsx`, ~90-140 lines each, following existing `payment-rejected.tsx`/`payment-accepted.tsx` shape) |
| Estimated authored changed lines (Phase 3: tests) | ~320 (new render-test file ~130, wiring-test extension ~120, migration idempotency test ~70) |
| **Total estimated authored changed lines** | **~965** |
| Generated-artifact lines (excluded above, but real diff) | 5 × `.blade.php` output under `backend/Modules/Email/resources/views/amenity-reservation-*.blade.php` (excluded from authored estimate; regenerated content, not hand-written) |
| 400-line review budget | **Exceeded — ~2.4×.** Consistent with `delivery_strategy: exception-ok` already accepted by the user for this change. |
| Chained PRs recommended | **No.** The three layers (engine flags, templates, tests) are tightly coupled — templates are meaningless without the engine flip, and the render tests are the only thing that catches a filename/slug mismatch, so splitting them across PRs would ship a broken intermediate state (silent plain-text fallback) between merges. If reviewer load becomes a blocker, the natural split points are Phase 2 (5 templates, each independently reviewable) and Phase 1+3 (engine + tests) as two PRs — but default to one branch per the accepted exception. |
| Decision needed before apply | **No** — `exception-ok` was already granted by the user for this change; this forecast is informational, not a new gate. |

---

## Task Dependency Summary

```
Phase 1 (1.1, 1.2, 1.3, 1.4)  ─┐  all parallel-safe, independent files
                                ├─► Phase 3 (3.1, 3.2, 3.3 parallel; 3.4 depends on 1.4)
Phase 2 (2.1..2.5 parallel)   ─┤            │
        └─► 2.6 (sequential)  ─┘            ▼
                                         3.5 (sequential — single test run)
                                              │
                                              ▼
                                    Phase 4 (4.1 → 4.2 → 4.3, sequential)
```
