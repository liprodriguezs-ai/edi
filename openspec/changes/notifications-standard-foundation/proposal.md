# SDD Proposal — Notifications standard foundation (Phase 0)

- **Change:** `notifications-standard-foundation`
- **Project:** `residia-monorepo` (Kivo7)
- **Phase:** propose — Phase 0 (foundation) of the notification-standardization effort
- **Artifact store:** openspec (this file) + engram topic `sdd/notifications-standard-foundation/proposal`
- **Date:** 2026-07-04
- **Source of truth:** approved notification audit (~46 trigger sites, only 6 config-gated). This proposal formalizes its Phase 0; the audit findings are verified — do not re-derive them.

---

## 1. Intent

### Problem
Kivo7 has a well-designed but under-used notification architecture: a central catalog `notification_event` (12 events) + the `App\Notifications\Concerns\NotifiesResidents` trait + a per-tenant config screen let each cluster control which notifications fire and via which channels. But of ~46 notification trigger sites in the backend, only **6** use the canonical config-gated path `notifyEvent()` (Collections 5 + SubscriptionPlan 1). The other ~40 fire via `notifyUsers()` / `notifyUsersFromTemplate()` / `toTenantRole()` — they reach the dispatcher (push/email/user preferences work) but **ignore the per-tenant `notification_setting`**, so the admin's Notifications config screen only truly controls Collections + trial. The rest are "notificaciones al aire": the screen promises control it doesn't have.

### Why now
The full migration (F1–F6, ~40 emitters, one SDD change per module) is approved but blocked on two foundations that don't exist yet:
1. A backend-enforced **`is_mandatory`** mechanism, so later phases can add critical events (blacklist_alert, message_reported, low_bank_balance, high_expense, employee_terminated) that admins can tune channels for but **cannot disable**.
2. A **proven conversion recipe**: 6 events already exist in the catalog but still fire ad-hoc. Converting them (zero new catalog rows) validates the recipe on real emitters before F1–F6 scale it.

Catalog drift (2 orphan template keys, missing `trial_expired` template) also compounds with every phase if not reconciled first.

### Success looks like
- The config screen becomes truthful for the 6 converted events: toggling them actually controls firing.
- `is_mandatory` is enforced in the **backend** (never trust the client), surfaced in the UI as an "Obligatorio" badge with a locked-on master switch and still-editable channel toggles.
- Template defaults reconcile 1:1 with catalog events.
- Later phases (F1–F6) only need to repeat a documented, tested recipe.

---

## 2. Scope

### In scope
1. **`is_mandatory` mechanism on the event catalog** — 5 wiring points, guarantee enforced backend-side:
   - **Migration** (`backend/Modules/Notification/database/migrations/`): add `is_mandatory` boolean NOT NULL DEFAULT false to `notification_event`, with `->comment('true = admin cannot deactivate the event; only channels')`.
   - **Model** `backend/Modules/Notification/app/Models/NotificationEvent.php`: `$fillable`, `casts()` (`'boolean'`), PHPDoc.
   - **Seed** `backend/database/seeders/CatalogSeeder.php` → `seedNotificationEvents()` (~L408): `'is_mandatory' => false` on all 12 existing rows (none of the current 12 are critical).
   - **Resolver** `backend/Modules/Notification/app/Services/NotificationSettingService.php`: `resolve()` returns `is_active = $event->is_mandatory ? true : $setting->is_active` (defensive); `allForTenant()` forces `is_active` true for mandatory and adds `is_mandatory` to the payload; `updateForTenant()` enforces `is_active` server-side for mandatory events regardless of the request body.
   - **Frontend** `frontend/src/modules/configuration/components/NotificationsConfigSection.tsx` + types in `frontend/src/modules/configuration/services/ConfigurationResource.ts`: `is_mandatory: boolean` on `INotificationEventConfig`; master Switch `checked={ev.is_mandatory ? true : ev.is_active}`, `disabled={ev.is_mandatory || !canUpdate || isPending}`; "Obligatorio" badge; channel toggles stay editable; `handleSave` sends `is_active: ev.is_mandatory ? true : ev.is_active`.
2. **Convert the 6 already-cataloged events to `notifyEvent()`** (zero new catalog rows — keys exist):

   | # | Event key | Current ad-hoc emitter | Note |
   |---|---|---|---|
   | 1 | `charge_created` | `backend/Modules/Collections/app/Services/ChargeGenerationService.php` (~L519) | |
   | 2 | `charge_overdue` | `backend/Modules/Collections/app/Services/ChargeStatusService.php` (~L238) | |
   | 3 | `visitor_at_gate` | `backend/Modules/Access/app/Services/AccessService.php` (~L289) | |
   | 4 | `new_board` | `backend/Modules/Board/app/Services/BoardService.php` (~L111) | |
   | 5 | `urgent_notice` | announcements/board broadcast path | exact call site pinned in sdd-design |
   | 6 | `monthly_statement` | none — cataloged with **no live emitter** | document as "wire when the statement job exists"; do NOT invent a trigger |

   Recipe (reference emitter: `backend/Modules/Collections/app/Services/PaymentService.php` L199-229): resolve config once (`app(NotificationSettingService::class)->resolve('{key}')`), gate on `is_active`, resolve recipients (`boardRecipientsForEvent()` for board audience; `unitBillingUserId()` / `unitResidentUserIds()` for residents), then `notifyEvent('{key}', $recipients, $context, $fallbackTitle, $fallbackBody, ['category' => ..., 'click_action' => ...], $resolved)`. The trait already wraps dispatch in `DB::afterCommit` + try/catch `report()` — keep emitting after commit.
3. **Catalog drift fix** — `backend/Modules/Configuration/database/seeders/NotificationTemplateSeeder.php`: remove (or explicitly justify) the 2 orphan template keys `charge_reminder` and `welcome_user`; add the missing `trial_expired` default template (event #11 exists with no default).
4. **Tests** (Standard Mode — alongside implementation, not test-first): backend — `updateForTenant()` forces `is_active` for a mandatory event; `resolve()` returns `is_active=true` for a mandatory event whose setting says false; `allForTenant()` includes `is_mandatory`; a converted emitter does NOT dispatch when the event is disabled. Frontend — mandatory row renders badge + disabled-on switch; save payload cannot disable a mandatory event.

### Out of scope (explicit)
- **All module migration phases F1–F6** (Access, Chat, Package, Treasury, Board/Housing/Collaborator, Projects/Providers/Reports) — each is its own future SDD change.
- **The Announcements special-case.**
- **The [C] cleanup**: NotificationUserJob direct-Firebase dedup, the EmailVerificationNotification dead-code decision, and SystemMailer (an INTENTIONAL bypass — leave it).
- **New catalog events**: the 5 future critical events ship with their module phases; Phase 0 builds only the mechanism (all 12 current rows stay `is_mandatory=false`).

---

## 3. Approach (intent/scope altitude — implementation belongs to sdd-design)

### Deliverable units & sequencing
1. **`is_mandatory` backbone** — migration → model → seeder → resolver, with the backend enforcement tests. First: it is the contract every later phase relies on.
2. **Config UI surface** — `INotificationEventConfig` type + `NotificationsConfigSection` badge/switch logic. Second: the screen is 100% server-driven, so it lands once the payload carries `is_mandatory`.
3. **The 6 emitter conversions** — grouped by owner module (Collections ×2, Access, Board, urgent_notice broadcast), each gated + tested; `monthly_statement` documented only.
4. **Template drift reconciliation** — `NotificationTemplateSeeder` orphans + `trial_expired`.
5. **Review** — `/review-stack` (laravel-architecture, react-frontend, integration, tenancy). Implementation is later delegated to `@notifications-expert` (domain owner), with `@email-expert` pulled in only if a new email template needs authoring (the `trial_expired` default).

### Rationale for key decisions
- **Backend-enforced mandatory, not UI-only:** a disabled switch is cosmetic; the guarantee must hold against any client. `updateForTenant()` never trusts the request body, and `resolve()` is defensive even if a stored setting row says false.
- **`is_mandatory` as a column on the CENTRAL catalog (no `tenant_id`):** criticality is a platform policy, uniform across clusters, and code branches on it — exactly the central-catalog rule. No tenancy change: `notification_event` stays central, `notification_setting` stays tenant-scoped.
- **Convert only already-cataloged events:** zero new catalog rows keeps the diff mechanism-focused and proves the recipe end-to-end before F1–F6 scale it to ~40 emitters.
- **All 12 rows seeded `is_mandatory=false`:** Phase 0 introduces no forced behavior for existing tenants; the only behavior change is the 6 conversions themselves.
- **Server-driven frontend stays:** no event list duplicated client-side; the UI just renders the new flag (`GET /config/notifications` → `allForTenant()`).
- **Emission semantics preserved:** converted emitters keep `DB::afterCommit` + try/catch `report()` via the trait; recipient fan-out already intersects Spatie role ∩ `user_tenant` (tenant-safe) — no change there.

---

## 4. Risks & open questions

- **New suppressibility (intended behavior change):** after conversion, the 6 events become disable-able per tenant. Assumption: default resolution for an event without a stored setting is active, so nothing stops firing at deploy time — sdd-design must confirm the settings-less default in `resolve()`.
- **`urgent_notice` call site is the least pinned** ("announcements/board broadcast path") — sdd-design must identify the exact emitter before conversion.
- **`monthly_statement` scope creep:** it has NO live emitter; the discipline is to document, not invent a trigger.
- **Orphan template removal:** confirm nothing resolves `charge_reminder` / `welcome_user` by key at runtime before deleting (grep before drop; if referenced, justify and keep).
- **Seeder idempotency:** `CatalogSeeder` upsert must not clobber tenant data; the column default false makes existing rows safe even before reseeding.
- **Migration risk: low** — additive boolean with default on a central table; no backfill, no tenant rows touched.

---

## 5. Success criteria

- `notification_event` has `is_mandatory` (NOT NULL, default false, commented); all 12 seeded rows carry `false`.
- Backend enforcement proven by tests: `updateForTenant()` forces `is_active` for mandatory; `resolve()` returns `is_active=true` for a mandatory event whose setting says false; `allForTenant()` payload includes `is_mandatory`.
- Config screen: mandatory events show the "Obligatorio" badge, master switch on + disabled, channels editable; the save round-trip cannot disable a mandatory event.
- The 5 live conversions fire through `notifyEvent()` gated on tenant config (test: disabled event → no dispatch); `monthly_statement` documented as cataloged-without-emitter.
- Template defaults 1:1 with catalog events: no orphan keys, `trial_expired` present.
- `php artisan test` (host php, Postgres 127.0.0.1) and `vitest run` green; identifiers English-only, UI strings Spanish, zero `any`.

---

## 6. Next phases

- **sdd-spec** and **sdd-design** can run in parallel (both depend only on this proposal).
- sdd-design must close: the exact `urgent_notice` emitter site; recipient resolution per converted event (board vs billing user vs unit residents); the settings-less default in `resolve()`; `click_action` / `NotificationCategory` per event; and the disposition of the 2 orphan templates (delete vs justify).
