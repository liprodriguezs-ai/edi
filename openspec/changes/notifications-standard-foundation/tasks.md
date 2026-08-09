# SDD Tasks — Notifications Standard Foundation (Phase 0)

- **Change:** `notifications-standard-foundation`
- **Project:** `residia-monorepo` (Kivo7)
- **Phase:** tasks
- **Artifact store:** hybrid
- **Date:** 2026-07-04
- **Delivery strategy:** ask-on-risk
- **TDD mode:** Standard (strict_tdd = FALSE) — tests written alongside implementation, not test-first
- **Depends on:** spec (engram `sdd/notifications-standard-foundation/spec` #1492), design (engram `sdd/notifications-standard-foundation/design` #1493), `openspec/changes/notifications-standard-foundation/design.md`
- **Implementation owner:** `@notifications-expert` (domain owner per project routing table)

---

## Work-unit overview

| # | Work unit | Commit boundary | Parallelizable after |
|---|-----------|-----------------|----------------------|
| WU-1 | `is_mandatory` backbone (migration → model → seeder → service enforcement → tests) | `feat(notification): add is_mandatory backbone to notification_event` | Start immediately |
| WU-2 | Config UI surface (type + badge + locked switch + tests) | `feat(configuration): surface is_mandatory in notifications config UI` | Start immediately (disjoint files from WU-1) |
| WU-3 | 4 emitter conversions (charge_created, charge_overdue, visitor_at_gate, new_board) | `feat(notification): convert 4 cataloged events to notifyEvent()` | Start immediately (touches only emitter files, not `NotificationSettingService.php`) |
| WU-4 | Template reconciliation (−2 orphans, +trial_expired, rewrite charge_overdue) | `feat(notification): reconcile template defaults with catalog` | **3.2 (charge_overdue rewrite) MUST land in the same commit/PR as WU-3**; the rest (orphans, trial_expired) is independent |
| WU-5 | Verification & review | `chore: run quality gates for notifications-standard-foundation` | After WU-1 to WU-4 |

---

## WU-1 — `is_mandatory` backbone

Satisfies: `notification-mandatory-events` (all 5 requirements)

### 1.1 Migration

- [ ] **1.1.1** Create `backend/Modules/Notification/database/migrations/2026_07_04_000001_add_is_mandatory_to_notification_event_table.php`. `up()`: `Schema::table('notification_event', fn (Blueprint $t) => $t->boolean('is_mandatory')->default(false)->after('is_active')->comment('true = admin cannot deactivate the event, only its channels; platform policy'));`. `down()`: `dropColumn('is_mandatory')`. Additive, no backfill.

### 1.2 Model

- [ ] **1.2.1** `backend/Modules/Notification/app/Models/NotificationEvent.php` — add `'is_mandatory'` to `$fillable` (L78-91); add `'is_mandatory' => 'boolean'` to `casts()` (L93-103); add `@property bool $is_mandatory` to the class PHPDoc (L18-30). Note: `CatalogSeeder::upsertCatalog()` calls `Model::upsert()` (raw query-builder upsert, bypasses mass-assignment/`$fillable` entirely) — the `$fillable` addition is for OTHER mass-assignment paths (e.g. `NotificationEvent::create()`), not required for seeding to work, but still correct model hygiene.

### 1.3 Catalog seed

- [ ] **1.3.1** `backend/database/seeders/CatalogSeeder.php::seedNotificationEvents()` (L408-582) — add `'is_mandatory' => false,` to all 12 row arrays. None are critical in Phase 0.

### 1.4 Service enforcement

- [ ] **1.4.1** `NotificationSettingService::resolve()` (L83-90 return) — change to `'is_active' => $event->is_mandatory ? true : (bool) $setting->is_active,` (defensive override even if the stored row says false).
- [ ] **1.4.2** `NotificationSettingService::allForTenant()` (L119, L134-147) — change the `$isActive` computation to `$event->is_mandatory ? true : ($setting !== null ? (bool) $setting->is_active : true)`; add `'is_mandatory' => (bool) $event->is_mandatory,` to the returned payload array.
- [ ] **1.4.3** `NotificationSettingService::updateForTenant()` (L192) — change `$setting->is_active = (bool) ($entry['is_active'] ?? true);` to `$event->is_mandatory ? true : (bool) ($entry['is_active'] ?? true);`. `$event` is already loaded at L181 (`$eventsByKey->get(...)`) — no extra query.

### 1.5 Backend tests

- [ ] **1.5.1** `backend/tests/Feature/Notification/NotificationSettingServiceTest.php` — add: `resolve()` returns `is_active=true` for a mandatory event whose stored `NotificationSetting.is_active` is `false` (mark one event `is_mandatory=true` in-test via `NotificationEvent::where('key', ...)->update(['is_mandatory' => true])` before asserting; non-mandatory events unaffected).
- [ ] **1.5.2** `backend/tests/Feature/Notification/NotificationSettingSeedingTest.php` — add: after `(new CatalogSeeder)->run()`, assert all 12 `NotificationEvent` rows have `is_mandatory === false` (no forced behavior for current tenants).
- [ ] **1.5.3** `backend/tests/Feature/Configuration/NotificationConfigTest.php` — add: `GET /config/notifications` payload includes `is_mandatory` key per event (assert present + `false` for all 12 by default). Add: mark one event mandatory, `PUT /config/notifications` with `is_active: false` for that event, assert the stored `NotificationSetting.is_active` is persisted as `true` (server never trusts the body) while `channel_email`/`channel_push` from the same request DO persist (channels stay independently editable).

---

## WU-2 — Config UI surface

Satisfies: `notification-config-ui` (all 4 requirements)

### 2.1 Type contract

- [ ] **2.1.1** `frontend/src/modules/configuration/services/ConfigurationResource.ts` — add `is_mandatory: boolean` to `INotificationEventConfig` (after L62, i.e. alongside `board_role_ids`). Leave `NotificationEventPatch` untouched — the `Pick` (L76-79) already excludes `is_mandatory`, so the client cannot round-trip it.

### 2.2 Component

- [ ] **2.2.1** `NotificationsConfigSection.tsx` — badge next to `ev.name` (L110): render `{ev.is_mandatory && <span className="rounded bg-[var(--surface-2)] px-1 py-0.5 text-[10px] font-medium uppercase tracking-wide text-[var(--ink-3)]">Obligatorio</span>}` (mirror the existing WhatsApp "Próximamente" badge pattern at L160-162).
- [ ] **2.2.2** Master `Switch` (L116-120): `checked={ev.is_mandatory ? true : ev.is_active}`, `disabled={ev.is_mandatory || !canUpdate || isPending}`.
- [ ] **2.2.3** Channel toggles (L127-164): no change — the existing `!ev.is_active` guard already keeps channels enabled for mandatory rows because the server always sends `is_active=true` for them.
- [ ] **2.2.4** `handleSave()` (L57-70): change the mapped `is_active` field to `is_active: ev.is_mandatory ? true : ev.is_active` (cosmetic — server enforces regardless, but keeps client state honest).

### 2.3 Frontend tests

- [ ] **2.3.1** Create `frontend/src/modules/configuration/components/__tests__/NotificationsConfigSection.test.tsx` (RTL + vitest, mirror `AmountModeSelector.test.tsx` pattern: `render()` + `screen` queries, no mocked router needed). Test: a mandatory event renders the "Obligatorio" badge and its master `Switch` is `disabled` + visually checked; a non-mandatory event has no badge and its switch is togglable. Test: clicking Save with a mandatory event present calls `notificationsMutation.mutate` with `is_active: true` for that event even if local edits attempted to set it false (simulate via a channel-toggle edit, not the disabled switch, to prove server-safety independent of the disabled control).

---

## WU-3 — Emitter conversions (4 live events)

Satisfies: `notification-event-conversion` (all 5 requirements, live-conversion scenarios)

Recipe per event: resolve config ONCE via `app(NotificationSettingService::class)->resolve('{key}')` (loop emitters resolve before the loop, not per unit), early-return if `!is_active`, then call `$this->notifyEvent('{key}', $recipients, $context, $fallbackTitle, $fallbackBody, $data, $resolved)` — pass `$resolved` as `$preResolved` to avoid a double `resolve()` call. `notifyEvent()` already wraps dispatch in `DB::afterCommit` + try/catch `report()` via the `NotifiesResidents` trait — no change to that safety net.

### 3.1 `charge_created` — `ChargeGenerationService::notifyBatchActivated()` (L511-531)

- [ ] **3.1.1** Convert to `notifyEvent('charge_created', ...)`. Recipients: `unitBillingUserId($unitId)` per charged unit (unchanged). Category `COLLECTIONS`, `click_action` `/statement`.
- [ ] **3.1.2** Context tokens: `resident_name` (billing user's `name`), `concept_name` (`$batch->policy?->concept?->name` via `ChargePolicy::concept()` → `ChargeConcept.name`), `amount`, `unit_label`, `due_date` (`$batch->due_date` — uniform across the batch, since every `Charge` created in the loop at L126 is stamped with the SAME `$batch->due_date`). `amount` is per-unit (varies via `AmountCalculator`/`by_share`): capture the `$charge` object already created in the `activateBatch()` loop (L118-135) and pass a `unitId => Charge` map into `notifyBatchActivated()` instead of the bare `$chargedUnitIds` array, so `amount` reads `$charge->expected_amount` without a re-query.
- [ ] **3.1.3** Resolve config once before the `foreach` (currently L513); early-return the whole method if `!is_active`.

### 3.2 `charge_overdue` — `ChargeStatusService::notifyNewlyOverdue()` (L226-249)

- [ ] **3.2.1** Convert to `notifyEvent('charge_overdue', ...)`. Recipients: `unitBillingUserId($unitId)` per newly-overdue unit (unchanged). Category `COLLECTIONS`, `click_action` `/statement`.
- [ ] **3.2.2** Context tokens: `resident_name`, `count` (already computed per unit at L228), `unit_label`. **Requires the template rewrite in WU-4/4.3 to land in the same commit** — the seeded `charge_overdue` template is currently per-charge shaped (`{concept_name}`,`{amount}`,`{days_past_due}`) and would render with empty tokens once the template row (which wins over the fallback) is hit.
- [ ] **3.2.3** Resolve config once before the `foreach` (currently L228); early-return the whole method if `!is_active`.

### 3.3 `visitor_at_gate` — `AccessService::notifyVisitorArrived()` (L287-300)

- [ ] **3.3.1** Convert to `notifyEvent('visitor_at_gate', ...)`. Recipients: `unitResidentUserIds($unitId)` — keep ALL residents (catalog description says "billing owner" but live code notifies all residents; correct behavior for a gate arrival, description is cosmetic and may be corrected in `seedNotificationEvents()` non-behaviorally). Category `ACCESS`, `click_action` `/my-access-log`.
- [ ] **3.3.2** **Preserve `'urgent' => true`** in the `$data` array passed to `notifyEvent()` — this is a load-bearing flag for the mobile client, not decorative.
- [ ] **3.3.3** Context tokens: `resident_name`, `visitor_name`, `unit_label`.

### 3.4 `new_board` — `BoardService::notifyBoardStarted()` L111 (ADR-4, product-confirmed 2026-07-04 — proceed)

- [ ] **3.4.1** Replace the L110-116 `notifyUsers($this->userIdsWithRole(['resident','renter']), 'Nueva mesa directiva', ...)` block with `notifyEvent('new_board', boardRecipientsForEvent('new_board', $resolved), $context, 'Nueva mesa directiva', 'Se conformó una nueva mesa directiva: '.$period->name.'.', ['click_action' => '/board-period', 'category' => NotificationCategory::SYSTEM, 'board_period_id' => $period->id], $resolved)`. Resolve `$resolved = app(NotificationSettingService::class)->resolve('new_board')` once at the top of `notifyBoardStarted()`, reused for both the gate and `boardRecipientsForEvent($preResolved: $resolved)`.
- [ ] **3.4.2** **Do NOT touch L101-108** (`'Asignación a la mesa directiva'` direct-assignment notice to `$memberUserIds` via SMS+email) — it is a distinct notice, not the `new_board` catalog event, and must stay admin-non-disable-able.
- [ ] **3.4.3** Context tokens: `cluster_name` = `tenant()?->name` (the established project pattern — same source used by `ResidentInvitationService`, `AuthController::AcceptInvitation`, and `RowImporter`; do NOT use `Company.legal_name`), `period` = `$period->name`.
- [ ] **3.4.4** No change to `createBoardAnnouncement()` (L119, `DB::afterCommit`) — residents keep receiving the community-wide announcement; this is the confirmed compensating mechanism from ADR-4.

### 3.5 Backend tests

- [ ] **3.5.1** Create `backend/tests/Feature/Notifications/EventConversionNotificationWiringTest.php` (mirror `PaymentNotificationWiringTest.php`: `use RefreshDatabase; use WithTenantSetup; protected $connectionsToTransact = [];` so `DB::afterCommit` fires). Cover, per event: (a) enabled → dispatch reaches the correct recipient (billing owner / all residents / board roles per §4 table) with a `NotificationUser` row created; (b) disabled (`NotificationSetting.is_active = false`) → zero `NotificationUser` rows; (c) tenant isolation — tenant B never receives tenant A's fan-out.
- [ ] **3.5.2** `new_board` test additionally asserts: the L102 direct-assignment notice STILL fires unconditionally (not gated by `notifyEvent`) to verify 3.4.2 was respected; and that board-role recipients (not all residents) receive the `new_board` notification.
- [ ] **3.5.3** `visitor_at_gate` test additionally asserts `'urgent' => true` is present on the recorded `Notification.click_action`/data payload (via the in-app row or dispatcher call assertion) — regression guard for 3.3.2.

---

## WU-4 — Template reconciliation

Satisfies: `notification-template-catalog-alignment` (all 3 requirements)

`backend/Modules/Configuration/database/seeders/NotificationTemplateSeeder.php::defaults()`.

- [ ] **4.1** Remove orphan key `notification_template.charge_reminder` (L31-34) — grep-confirmed zero runtime resolvers, superseded by the live `payment_due_reminder`. Final check before deleting: `rg "charge_reminder" backend/` must show only this seeder file.
- [ ] **4.2** Remove orphan key `notification_template.welcome_user` (L73-76) — grep-confirmed zero runtime resolvers, no catalog event, no emitter. Same final-check discipline as 4.1.
- [ ] **4.3** **Rewrite** `notification_template.charge_overdue` (L35-38) to the count shape: title `'Cargo vencido'` (or keep), body `'Hola {resident_name}, tienes {count} cargo(s) vencido(s) en tu unidad {unit_label}. Regulariza para evitar recargos.'`. **Must land together with WU-3.2** (the emitter conversion) — an inconsistent intermediate state (converted emitter + stale per-charge template) renders empty tokens.
- [ ] **4.4** Add missing `notification_template.trial_expired` — title `'Prueba de {module} vencida'`, body `'La prueba de {module} venció. Inclúyela en el cobro del clúster para no perder el acceso.'`. Event `supports_email=false` → push/in-app only, **no email `.tsx`, no `@email-expert` delegation needed**. Its emitter (`BillingNotificationService::notifyTrialExpired()`) already calls `notifyEvent('trial_expired', ..., ['module' => ...])` — this task only adds the missing default template row, no emitter change.
- [ ] **4.5** No data migration needed — `Setting::updateOrCreate` by slug is idempotent; removing orphan keys stops seeding them for NEW tenants but leaves harmless stale rows in existing tenants (nothing resolves them at runtime).

---

## WU-5 — Verification & review

- [ ] **5.1** `php artisan test --filter=NotificationSettingServiceTest` — green (host php, Postgres `127.0.0.1`, ONE test process at a time per project convention).
- [ ] **5.2** `php artisan test --filter=NotificationSettingSeedingTest` — green.
- [ ] **5.3** `php artisan test --filter=NotificationConfigTest` — green.
- [ ] **5.4** `php artisan test --filter=EventConversionNotificationWiringTest` — green.
- [ ] **5.5** `php artisan test --filter=PaymentNotificationWiringTest` — green (regression guard: WU-1's service changes must not break the already-converted Collections emitters).
- [ ] **5.6** `vendor/bin/pint --dirty` (from `backend/`) — zero formatting errors.
- [ ] **5.7** `pnpm test` (from `frontend/`, `vitest run`) — green, including the new `NotificationsConfigSection.test.tsx`.
- [ ] **5.8** `pnpm lint` && `pnpm build` (from `frontend/`) — zero ESLint errors, zero `any`, TypeScript strict compile succeeds.
- [ ] **5.9** `/review-stack` — expected reviewers: `laravel-architecture-reviewer` (service/emitter changes, central-catalog placement of `is_mandatory`), `react-frontend-reviewer` (config UI diff), `integration-reviewer` (payload contract — `is_mandatory` field, `NotificationEventPatch` exclusion), `tenancy-reviewer` (confirm `notification_event` stays central/no `tenant_id`, `notification_setting` isolation unaffected by the mandatory override). Address CRITICAL findings before declaring ready.

---

## Spec → Task traceability matrix

| Spec requirement (domain) | Satisfied by |
|---|---|
| Central catalog column + migration + model + seeder (`notification-mandatory-events`) | WU-1.1, WU-1.2, WU-1.3 |
| `resolve()` mandatory-aware | WU-1.4.1, WU-1.5.1 |
| `allForTenant()` payload carries `is_mandatory` | WU-1.4.2, WU-1.5.3 |
| `updateForTenant()` server-enforces | WU-1.4.3, WU-1.5.3 |
| No forced behavior for current tenants | WU-1.3, WU-1.5.2 |
| Config-gated firing, 4 live events (`notification-event-conversion`) | WU-3.1–3.4, WU-3.5.1 |
| Recipient routing by audience | WU-3.1–3.4, WU-3.5.1–3.5.2 |
| `monthly_statement`/`urgent_notice` stay documented-only, zero call sites | No task creates one (verified absent by design; nothing to implement) |
| Emission semantics preserved (afterCommit + swallow-report) | WU-3.1–3.4 (unchanged trait behavior, no new task needed) |
| Settings-less default resolves active | Already confirmed live by design §2; regression-covered by WU-1.5.1 and existing `NotificationSettingServiceTest` |
| No orphan template keys / every event has a default (`notification-template-catalog-alignment`) | WU-4.1, WU-4.2, WU-4.4 |
| Re-seed idempotent, non-destructive | WU-4.5 |
| `is_mandatory` flows through type contract (`notification-config-ui`) | WU-2.1 |
| Mandatory master switch locked-on + badge | WU-2.2.1, WU-2.2.2, WU-2.3.1 |
| Channel toggles stay editable on mandatory rows | WU-2.2.3 |
| Save payload cannot disable a mandatory event | WU-2.2.4, WU-2.3.1 |

---

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~480–560 (backend prod ~140, backend tests ~280, frontend prod ~15, frontend tests ~100) |
| 400-line budget risk | Medium |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 → PR 2 → PR 3 (see below) |
| Delivery strategy | ask-on-risk |
| Chain strategy | pending — user must pick stacked-to-main vs feature-branch-chain |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: Medium

### Suggested Work Units (PR slices)

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | WU-1 (backbone) + WU-2 (config UI) | PR 1 | Self-contained: adds the mechanism + surfaces it; ~250 lines incl. tests. No behavior change (all 12 rows stay `is_mandatory=false`). |
| 2 | WU-3 (4 emitter conversions) + WU-4.3 only (charge_overdue template rewrite, MUST travel with 3.2) | PR 2 | Highest-risk slice — includes the ADR-4 `new_board` recipient behavior change (product-confirmed) and the only genuine runtime behavior shift in Phase 0; ~300 lines incl. tests. Depends on PR 1 merging first (uses `NotificationSettingService::resolve()` as modified, though functionally compatible either order since all events are non-mandatory). |
| 3 | WU-4.1/4.2/4.4 (orphan removal + trial_expired) + WU-5 (verification, `/review-stack`) | PR 3 | Independent of PR 2's charge_overdue coupling; ~40–60 lines. Can actually ship in parallel with PR 2 if reviewers prefer — only WU-5 needs both merged first. |

Note: if the maintainer prefers a single PR, `size:exception` is the third chain-strategy option — ask before applying, since delivery strategy is `ask-on-risk` (not `auto-chain`/`exception-ok`).
