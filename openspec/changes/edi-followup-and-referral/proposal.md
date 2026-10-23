# Proposal: EDI Follow-Up and Referral

## Intent

The screening result mandates a conduct — green: keep attending stimulation sessions; yellow: reapply EDI in 3 months, refer if it recurs; red: refer to a specialist and enable the confirmatory (Battelle) test. Today the result dies in `screening`: nothing tracks the conduct, so detection has no consequence — the exact failure the product exists to fix. This change delivers the `FollowUp` module: follow-ups auto-created from the completed screening, a referral record, a work inbox ("what do I still have to chase"), and a notification when a referral is registered.

## Scope

### In Scope
- Backend `Modules/FollowUp`: tenant-scoped `follow_up` + `referral`; CENTRAL catalogs `follow_up_type`, `follow_up_status`; tenant-scoped `referral_specialty` seeded by `TenantCatalogSeeder`.
- New `ScreeningCompleted` event dispatched by `ScreeningLifecycleService::complete()` + idempotent listener that auto-closes open `reassess_3_months` follow-ups of the child and creates new follow-ups per result color (event-driven, no polling).
- Daily per-tenant idempotent `follow-up:refresh-overdue` command materializing `overdue`.
- Notification `follow_up.referral_created` through the existing engine (`notifyEvent` + `NotificationEvent` catalog row). No new channel.
- API: Orion CRUD + `start`/`complete`/`cancel` transitions + referral resource.
- React `followUp` module: `FollowUpListPage` (status/due filters), `FollowUpDetailPage` (history + referrals), referral-specialty catalog page; full module registration incl. `$mapperSelects`/`CatalogType`.

### Out of Scope
- Due/overdue reminder notifications (future cron-driven event).
- Email channel for the referral notification (in-app + push only in v1).
- Reacting to screening invalidation (manual cancel covers it); Battelle capture UI; reports; Stimulation linkage; any scoring-engine change.

## Capabilities

### New Capabilities
- `follow-up-and-referral`: conduct tracking derived from screening results — auto-creation, lifecycle, auto-close, overdue derivation, referrals, notification, work inbox.

### Modified Capabilities
- `screening-application`: completing a screening now emits `ScreeningCompleted` (delta REQ-SCR-016).

## Approach

`complete()` dispatches `ScreeningCompleted` inside its transaction; a synchronous listener in `FollowUp` closes open reassessments (`closed_by_screening_id`) then creates follow-ups per color, atomic with completion. A partial unique `(screening_id, follow_up_type_id)` makes replays idempotent. `overdue` is a derived state materialized by a convergent daily command (design D7). Referral creation notifies via `DispatchesNotifications` (afterCommit-safe). House patterns throughout (no DB FKs, partial uniques, fixed-UUID central catalogs).

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `backend/Modules/FollowUp/` | New | Tables, models, listener, services, API, command |
| `backend/Modules/Screening/` | Modified | New event class + one dispatch in `complete()`; engine untouched |
| `backend/database/seeders/*`, `AngularController` | Modified | Catalogs, registration, selects |
| `frontend/src/` | Modified | `followUp` module, catalog page, routes, `useCatalogs` |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Duplicate follow-ups on event replay | Med | DB partial unique + firstOrCreate; test |
| Listener bug blocks completion (atomic coupling) | Low | Tiny pure listener; full test matrix; documented tradeoff (design D1) |
| Regression in Screening module | Low | Surgical touch; suite gate from 737 baseline |

## Rollback Plan

Everything is additive: new tables, new seeder rows, one event class + one dispatch line. Revert the PR(s); `migrate:rollback` drops the 5 tables; removing the dispatch restores `complete()` byte-identical.

## Dependencies

- `edi-screening-application` delivered (lifecycle, `result_color_id`, frozen `previous_result_color_id`).
- Notification module (already present; verified fallback behavior).

## Success Criteria

- [ ] Red → `specialist_referral` + `confirmatory_test` follow-ups; first yellow → `reassess_3_months`; recurrent yellow → also `specialist_referral`; green → none.
- [ ] A later completed screening of the same child closes open reassessments with `closed_by_screening_id`; double completion/replay duplicates nothing.
- [ ] `overdue` never client-written; command idempotent per tenant.
- [ ] `follow_up.referral_created` dispatched honoring per-tenant settings.
- [ ] Backend green from 737; frontend green from 221.

## Proposal question round

Auto mode — no blocking round. Defaults encoded in `design.md` §8 (due-date offsets, recurrence definition, recipients, email channel, invalidation reaction); correct them before `sdd-apply`.
