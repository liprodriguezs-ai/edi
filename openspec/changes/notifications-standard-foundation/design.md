# SDD Design — Notifications standard foundation (Phase 0)

- **Change:** `notifications-standard-foundation`
- **Project:** `residia-monorepo` (Kivo7)
- **Phase:** design (the HOW at architectural level)
- **Artifact store:** hybrid (this file + engram `sdd/notifications-standard-foundation/design`)
- **Date:** 2026-07-04
- **Reads:** proposal (engram #1491 + `openspec/changes/notifications-standard-foundation/proposal.md`). Every backend file referenced below was read this phase from the MAIN checkout (`/Users/rodriguez/code/residia/backend/**`, `/frontend/**`).
- **Authority order on conflict:** laravel-backend-architecture / stancl-tenancy / react-frontend-architecture skills > `CLAUDE.md` > this design. This design applies skill rules, it does not restate them.
- **Mode:** Standard (strict_tdd=false — tests alongside implementation).

This design closes the 5 open questions the proposal handed to sdd-design, grounded in the LIVE code. It is the architectural HOW; the WHAT-to-do steps belong to sdd-tasks.

---

## 0. Executive summary

Phase 0 adds one central-catalog column (`is_mandatory`) enforced **backend-side** across `NotificationSettingService::{resolve, allForTenant, updateForTenant}`, surfaces it in the server-driven config UI, and converts already-cataloged ad-hoc emitters to the config-gated `notifyEvent()` recipe. Reading the code REVISED the conversion count: **only 4 events have a clean, in-scope emitter** — `charge_created`, `charge_overdue`, `visitor_at_gate`, `new_board`. `urgent_notice` has NO standalone emitter (its only path is the out-of-scope general Announcements broadcast) and `monthly_statement` has no emitter at all — **both are documented-only, NOT converted.** `trial_expired` is ALREADY on `notifyEvent()` (BillingNotificationService) — Phase 0 only adds its missing default template (push/in-app, `{module}` token, no email `.tsx`, no @email-expert needed). Orphan templates `charge_reminder` + `welcome_user` have zero runtime resolvers (grep-confirmed) → remove. Settings-less `resolve()` returns `is_active=true`, so converting the 4 events silences nothing at deploy.

---

## 1. Architecture approach — ride existing seams, invent nothing

No new patterns. Three proven seams carry the whole change:

- **`is_mandatory`** is a boolean on the CENTRAL `notification_event` catalog (no `tenant_id`) — criticality is a uniform platform policy the code branches on, exactly the central-catalog rule. `notification_setting` stays tenant-scoped. No tenancy change.
- **Emitter conversion** = the `PaymentService::submit()` recipe (read L196-230): resolve config ONCE (`app(NotificationSettingService::class)->resolve('{key}')`), gate on `is_active`, resolve recipients via the `NotifiesResidents` helpers, then `notifyEvent('{key}', $recipients, $context, $fallbackTitle, $fallbackBody, ['category'=>…, 'click_action'=>…], $resolved)`. The trait already wraps dispatch in `DB::afterCommit` + try/catch `report()` — emission stays after commit; recipient fan-out already intersects Spatie role ∩ `user_tenant`. Pass `$resolved` as `$preResolved` to avoid the double-resolve (arch W1).
- **Config UI** is 100% server-driven (`GET /config/notifications` → `allForTenant()`). The screen just renders the new flag; no event list is duplicated client-side.

---

## 2. Q3 — Settings-less default in `resolve()` (CLOSED, confirmed active)

`NotificationSettingService::resolve()` returns `is_active=true` in BOTH settings-less branches: no tenant context (L58-68, event `default_channels`) and tenant context but no `notification_setting` row (L74-81). Only the setting-EXISTS branch (L83-90) can yield `is_active=false`. **Consequence:** converting the 4 events does NOT silence them at deploy — a tenant with no row still fires. Existing tenants receive rows via `TenantNotificationSettingSeeder` (on `TenantCreated`), so their admin-chosen state is honored. This is the safety guarantee the proposal's risk #1 required.

---

## 3. Q4 — `is_mandatory` wiring (5 points, exact edits)

**Contract:** the client NEVER decides mandatory; the server enforces it everywhere. `NotificationEventPatch` (a `Pick`) already excludes `is_mandatory`, so the client cannot even send it.

| # | File | Edit |
|---|------|------|
| 1 | `backend/Modules/Notification/database/migrations/2026_07_04_000001_add_is_mandatory_to_notification_event_table.php` (new) | `Schema::table('notification_event', fn($t) => $t->boolean('is_mandatory')->default(false)->after('is_active')->comment('true = admin cannot deactivate the event, only its channels; platform policy'));` down: `dropColumn`. Additive, no backfill. |
| 2 | `backend/Modules/Notification/app/Models/NotificationEvent.php` | add `'is_mandatory'` to `$fillable`; `'is_mandatory' => 'boolean'` in `casts()`; `@property bool $is_mandatory` PHPDoc. |
| 3 | `backend/database/seeders/CatalogSeeder.php::seedNotificationEvents()` (L410-581) | add `'is_mandatory' => false` to ALL 12 rows (none are critical in Phase 0). |
| 4 | `NotificationSettingService.php` | **`resolve()` L84:** `'is_active' => $event->is_mandatory ? true : (bool) $setting->is_active` (defensive override even if a stored row says false). **`allForTenant()` L119:** `$isActive = $event->is_mandatory ? true : ($setting !== null ? (bool) $setting->is_active : true);` and add `'is_mandatory' => (bool) $event->is_mandatory` to the returned payload map (L134-147). **`updateForTenant()` L192:** `$setting->is_active = $event->is_mandatory ? true : (bool) ($entry['is_active'] ?? true);` — `$event` is already loaded at L181, so no extra query. |
| 5 | `frontend/src/modules/configuration/services/ConfigurationResource.ts` + `components/NotificationsConfigSection.tsx` | type + UI, below. |

**Frontend type** (`INotificationEventConfig`, after L62): add `is_mandatory: boolean`. Leave `NotificationEventPatch` untouched (the `Pick` already excludes it — client never round-trips mandatory).

**`NotificationsConfigSection.tsx`:**
- **Badge** (next to `ev.name`, L110): `{ev.is_mandatory && <span className="rounded bg-[var(--surface-2)] px-1 py-0.5 text-[10px] font-medium uppercase tracking-wide text-[var(--ink-3)]">Obligatorio</span>}`.
- **Master Switch** (L116-120): `checked={ev.is_mandatory ? true : ev.is_active}`, `disabled={ev.is_mandatory || !canUpdate || isPending}`.
- **Channel toggles** stay editable: current guard `disabled={… || !ev.is_active}` already works because a mandatory event's `ev.is_active` arrives `true` from the server. No change needed.
- **`handleSave`** (L58-65): `is_active: ev.is_mandatory ? true : ev.is_active` (cosmetic — server enforces regardless).

---

## 4. Q2 — Recipient resolution per converted event (CLOSED)

| Event | Emitter (site to convert) | Recipients (helper) | Category | click_action | Context tokens |
|-------|---------------------------|---------------------|----------|--------------|----------------|
| `charge_created` | `ChargeGenerationService::notifyBatchActivated()` L519 | `unitBillingUserId($unitId)` per charged unit | `COLLECTIONS` | `/statement` | resident_name, concept_name, amount, unit_label, due_date |
| `charge_overdue` | `ChargeStatusService::notifyNewlyOverdue()` L238 | `unitBillingUserId($unitId)` per newly-overdue unit | `COLLECTIONS` | `/statement` | resident_name, **count**, unit_label |
| `visitor_at_gate` | `AccessService::notifyVisitorArrived()` L289 | `unitResidentUserIds($unitId)` (ALL residents, keep) | `ACCESS` | `/my-access-log` | resident_name, visitor_name, unit_label — **preserve `'urgent' => true`** |
| `new_board` | `BoardService::notifyBoardStarted()` **L111** (replace) | `boardRecipientsForEvent('new_board', $resolved)` | `SYSTEM` | `/board-period` | cluster_name, period |

Loop emitters (`charge_created`, `charge_overdue`) resolve config ONCE before the loop, early-return on `!is_active`, then `notifyEvent(..., $resolved)` per unit.

Two per-event notes that are load-bearing:

- **`charge_overdue` template↔emitter mismatch.** The emitter aggregates PER UNIT with a `count` ("Tienes N cargos vencidos"), but the seeded `notification_template.charge_overdue` body is per-CHARGE (`{concept_name}`, `{amount}`, `{days_past_due}`), which would render with empty tokens once gated (the template row wins over the fallback). **Decision:** rewrite that template body to the count shape — `"Hola {resident_name}, tienes {count} cargo(s) vencido(s) en tu unidad {unit_label}. Regulariza para evitar recargos."` — and pass `{resident_name, count, unit_label}`. This folds into the §6 template reconciliation. Do NOT switch the emitter to per-charge (out of scope; would change fan-out).
- **`visitor_at_gate` audience wording.** Catalog description says "billing owner" but the code notifies `unitResidentUserIds` (ALL active residents). Keep all-residents (correct for a gate arrival, not a billing matter); the description text is cosmetic and may be corrected in the seeder (non-behavioral).

---

## 5. Q1 + `new_board` audience — the two reconciliations (CLOSED)

### 5.1 `new_board` — honor the catalog (audience=board). BEHAVIOR CHANGE, flagged.

`BoardService::notifyBoardStarted()` fires TWO `notifyUsers` calls:
- **L102** "Asignación a la mesa directiva" → the newly-assigned `$memberUserIds`, channels `['log','sms','resend_email']`. A direct assignment confirmation.
- **L111** "Nueva mesa directiva" → `userIdsWithRole(['resident','renter'])` (ALL residents). A community broadcast.

Catalog `new_board`: audience=**board**, category=SYSTEM, description "Se envía a los nuevos miembros de la mesa directiva cuando se abre un período", template body "Se conformó una nueva mesa directiva…". 

**Decision: convert L111 → `notifyEvent('new_board', boardRecipientsForEvent('new_board', $resolved), …)`; keep L102 as-is.** Recipients change from all-residents to the tenant-configured board roles (≈ the just-assigned members, since roles are granted in the same transaction). Rationale:
1. audience=board is the contract; the config screen renders a **board-role recipient selector** for this event — wiring recipients to residents would make that selector a lie (the exact "notificaciones al aire" defect Phase 0 exists to kill).
2. Residents do NOT lose the news: `notifyBoardStarted()` ALSO auto-creates a `target_type='all'`, `channel_push=true` announcement (L119 `createBoardAnnouncement`) that already broadcasts "Nueva mesa directiva" community-wide. L111 is largely redundant with it.
3. L102 is a distinct direct assignment notice with its own SMS+email semantics and must NOT become admin-disable-able — it is not the `new_board` catalog event.

**Flag:** this is the one genuine behavior change in Phase 0 (residents stop getting the DUPLICATE direct push; they keep the announcement). If product wants residents to retain a dedicated direct push, the correct fix is a NEW resident-audience event in a later phase, not bending `new_board`. sdd-tasks should carry this as a confirm-before-apply item.

### 5.2 `urgent_notice` — DEFER (no convertible in-scope emitter).

Grep-verified: `URGENT_NOTICE` appears ONLY as a model const + a `CatalogSeeder` row + its template. There is **no** `notifyEvent('urgent_notice')` and no dedicated board-urgent trigger. The only "urgent" path is `AnnouncementService::dispatchPublishedNotifications()` (L310-341), which broadcasts EVERY published announcement to `audienceUserIds($announcement)` (the announcement's own target: all/by_type/by_tower/…), with its own per-announcement `channelsFor()` logic, and merely sets `$data['urgent']=true` when priority=URGENT. That is the **general Announcements broadcast — explicitly OUT OF SCOPE** (proposal §Scope-out "The Announcements special-case").

**Decision:** `urgent_notice` joins `monthly_statement` as **cataloged-with-no-convertible-emitter → document only, do NOT wire.** Converting it would require either reworking the announcements broadcast (out of scope) or inventing a board-only urgent trigger (forbidden by the "do not invent a trigger" discipline). **Phase 0 LIVE conversions = 4** (`charge_created`, `charge_overdue`, `visitor_at_gate`, `new_board`); **documented-only = 2** (`urgent_notice`, `monthly_statement`).

---

## 6. Q5 — Template disposition (CLOSED)

`NotificationTemplateSeeder::defaults()` holds 13 keys; the catalog has 12 events. Reconcile 1:1:

- **REMOVE 2 orphans** — `notification_template.charge_reminder` (L31) and `notification_template.welcome_user` (L73). Grep across `backend/` found each ONLY in this seeder — no `notifyUsersFromTemplate`/`TemplateRenderer::render` resolves them at runtime. `charge_reminder` is superseded by the live `payment_due_reminder`; `welcome_user` has no event and no emitter. Safe to delete.
- **ADD 1 missing** — `notification_template.trial_expired`. Its emitter is ALREADY live (`BillingNotificationService::notifyTrialExpired()` → `notifyEvent('trial_expired', …)`, context `['module' => …]`). Template MUST use the `{module}` token to match: title `"Prueba de {module} vencida"`, body `"La prueba de {module} venció. Inclúyela en el cobro del clúster para no perder el acceso."` Event `supports_email=false` (push/in-app only) → **no email `.tsx`, no @email-expert delegation.**
- **REWRITE 1** — `notification_template.charge_overdue` to the count shape (see §4).

Idempotency: `NotificationTemplateSeeder` is `updateOrCreate` by slug per tenant; removing orphan keys stops seeding them for NEW tenants but leaves stale rows in existing tenants (harmless — nothing resolves them). No data migration required for Phase 0.

---

## 7. File changes

| File | Action | Description |
|------|--------|-------------|
| `Modules/Notification/database/migrations/2026_07_04_000001_add_is_mandatory_…php` | Create | additive boolean + comment |
| `Modules/Notification/app/Models/NotificationEvent.php` | Modify | fillable + cast + PHPDoc |
| `database/seeders/CatalogSeeder.php` | Modify | `is_mandatory=false` on 12 rows |
| `Modules/Notification/app/Services/NotificationSettingService.php` | Modify | mandatory override in resolve/allForTenant/updateForTenant |
| `Modules/Collections/app/Services/ChargeGenerationService.php` | Modify | `notifyBatchActivated` → `notifyEvent('charge_created')` |
| `Modules/Collections/app/Services/ChargeStatusService.php` | Modify | `notifyNewlyOverdue` → `notifyEvent('charge_overdue')` |
| `Modules/Access/app/Services/AccessService.php` | Modify | `notifyVisitorArrived` → `notifyEvent('visitor_at_gate')`, keep `urgent` |
| `Modules/Board/app/Services/BoardService.php` | Modify | replace L111 broadcast with `notifyEvent('new_board')`; keep L102 |
| `Modules/Configuration/database/seeders/NotificationTemplateSeeder.php` | Modify | −2 orphans, +`trial_expired`, rewrite `charge_overdue` |
| `frontend/src/modules/configuration/services/ConfigurationResource.ts` | Modify | `is_mandatory: boolean` on interface |
| `frontend/src/modules/configuration/components/NotificationsConfigSection.tsx` | Modify | badge + locked master switch + save value |

---

## 8. Testing strategy (Standard Mode)

| Layer | What | Approach |
|-------|------|----------|
| Backend | `updateForTenant()` forces `is_active=true` for a mandatory event even when body sends false | seed a mandatory row, PUT false, assert stored true |
| Backend | `resolve()` returns `is_active=true` for a mandatory event whose setting says false | insert setting `is_active=false` on a mandatory event, assert resolve true |
| Backend | `allForTenant()` payload includes `is_mandatory` | assert key present + correct per event |
| Backend | converted emitter respects gate | disable `charge_created`/`charge_overdue`/`visitor_at_gate`/`new_board` for tenant → assert NO Notification/NotificationUser rows; enabled → assert dispatch + correct recipients (billing owner / all residents / board roles) |
| Backend | tenant isolation | second tenant's data never receives the fan-out |
| Frontend | mandatory row renders "Obligatorio" badge + disabled-on master switch, channels still editable | render + assert |
| Frontend | save payload cannot disable a mandatory event | `handleSave` emits `is_active=true` for mandatory |

Run on host php + Postgres `127.0.0.1` (per CLAUDE.md; ONE test process at a time). Frontend `vitest run`. Final `/review-stack` (laravel-architecture, react-frontend, integration, tenancy). Implementation delegated to `@notifications-expert`.

---

## 9. ADR-style decision log

- **ADR-1 — `is_mandatory` enforced backend-side, not UI-only.** A disabled switch is cosmetic; `updateForTenant()` never trusts the body and `resolve()` is defensive. **Rejected:** client-trusted flag (bypassable).
- **ADR-2 — `is_mandatory` on the CENTRAL catalog (no `tenant_id`).** Criticality is uniform platform policy the code branches on. **Rejected:** per-tenant column (would let a cluster self-exempt from a critical event).
- **ADR-3 — Phase 0 converts 4 events, defers `urgent_notice`.** Only 4 have a clean in-scope emitter; `urgent_notice`'s only path is the out-of-scope Announcements broadcast. **Rejected:** reworking the broadcast or inventing a board-urgent trigger (scope creep; violates "don't invent triggers").
- **ADR-4 — `new_board` honors catalog audience=board.** Convert the L111 all-residents broadcast to board-role recipients; residents keep the auto-announcement. **Rejected:** wiring recipients to residents (makes the config board-role selector a lie).
- **ADR-5 — Remove orphan templates `charge_reminder`/`welcome_user`; add `trial_expired`.** Grep-confirmed zero runtime resolvers for the orphans; `trial_expired` emitter is already live and needs its default. **Rejected:** keep orphans "just in case" (misleads maintainers, drifts every phase).

---

## 10. Open items for sdd-tasks / apply (not blockers)

- [x] Product CONFIRMED (2026-07-04): ADR-4 accepted — `BoardService::notifyBoardStarted()` L111 converts to `notifyEvent('new_board', boardRecipientsForEvent(...))` (board-role recipients per catalog); L102 direct-assignment notice stays untouched; residents keep the auto-created `target_type=all` cluster announcement. Proceed to apply.
- [ ] `charge_created` context: confirm `concept_name`/`due_date` sources on the batch vs per-charge at the L519 loop (batch has `title`; per-unit charge carries `due_date`). Apply reads the loop to map exact tokens.
- [ ] `cluster_name` source for `new_board` (Company legal_name vs Tenant name) — pick the one other templates use.
- [ ] Confirm `upsertCatalog` maps `is_mandatory` (relies on `$fillable`) — add before seeding.
