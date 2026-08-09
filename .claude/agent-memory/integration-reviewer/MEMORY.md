# Integration Reviewer Memory — Index

- [DataImport status contract](dataimport-status-contract.md) — async import polling seam: status/phase unions, report keys, error path
- [Contract conventions](contract-conventions.md) — project-wide back↔front contract shapes (Orion, errors, money, report endpoints)
- [Tableros-contables report seam](tableros-contables-report-seam.md) — 5 report dashboard contracts: violations by endpoint (2026-06-17)
- [Collections seam](collections-seam.md) — grace_days cap, custom-action alignment (2026-06-20). NOTE: its "relation key drift" finding was DISPROVEN 2026-06-25 — see correction at top of file
- [ChargePolicy seam](chargepolicy-seam.md) — concept→policy split; due_soon_days(0-60) + surcharge_rules(bp/minor-unit) seam ALIGNED; verified Eloquent snake-cases relation keys (2026-06-25)
- [Access seam](access-seam.md) — filter/filterableBy ALIGNED (2026-06-27); prior camelCase drift FIXED; WARNING: date-to-bound narrow for timestamp cols (qr_expires_at, created_at)
- [Subscription seam](subscription-seam.md) — Stripe checkout/portal/cancel/change-plan: all 5 endpoints ALIGNED; current_period_start hardcoded null by design; discard-and-refetch pattern on mutations; 403/409/404 handled globally not distinctly (2026-06-21)
- [ChargeBatch summary seam](chargebatch-summary-seam.md) — server-side KPI aggregation + by-unit pagination: all 6 checks PASS; money double-source (formatMinorUnits vs moneyToMinorUnits) both correct; page in query string; latent owner_ids divergence noted (2026-06-26)
- [Package seam](package-seam.md) — WU-6 catalog admin (carrier/type): 2 CRITICALs — `order:null` rejected (NOT NULL col + non-nullable FormRequest vs canonical tower) + menu route `/package-carrier` vs FE `/package/carriers` (2026-06-29)
- [Chat channel seam](chat-channel-seam.md) — TWO merged reviews: Fase 1 contract (can_read/can_write via search, composer gate, auto-join removed; WARNING auth middleware gap on Orion resources, 2026-06-28) + by_type multi/OR + board/read+write scope ALIGNED; WARNING: legacy by_floor re-edit blocked by string vs array mismatch in SingleTargetSelector (2026-06-30)
- [Billing v2 admin seam](billing-v2-admin-seam.md) — BillingPackage CRUD + PriceMatrix + change-billing-plan: 0 CRITICALS; 3 WARNINGs: min:0 vs min:1 for unit ranges, trial_days non-nullable TS vs nullable DB, changeBillingPlan response data always null via safeParse (2026-07-01)
- [Delinquency restrictions seam](delinquency-restrictions-seam.md) — 3 seams aligned; KEY PATTERN: any business-rule 403 opens the global PermissionsModal unless opted out in handle403 (2026-07-14)
- [Delinquency dashboard filters seam](delinquency-dashboard-filters-seam.md) — server-side filters aligned; 2 WARNINGs: silent 422 on query (no isError + no input clamps), unit_id nullability drift (2026-07-19)
- [Complaint seam](complaint-seam.md) — Denuncias module: 0 CRITICALs; business-403 PermissionsModal double-surface (no skipPermissionsModal), assign UI offers non-managers, FE FSM matrix mirrors backend exactly (2026-07-19)
- [AmenityReservations earmark seam](amenity-reservations-earmark-seam.md) — S8 plug-in into Collections earmark subsystem; 199 tests live-verified green; WARNING: amenity_reservation_id missing from Charge FormRequests' prohibited list (not exploitable, allowlist-protected); S13/S15 gotcha: submit()'s bank_account_id default-to-primary breaks the handler's amenity-account-only check (2026-07-06)

## Recovered 2026-07-20 from stray `backend/.claude/agent-memory` copy

- [Notification config seam](notification-config-seam.md) — GET/PUT /config/notifications contract review (Phase 5)
- [click_action seam](click-action-seam.md) — convention + full route table (19 destinations, all verified 2026-06-28)
- [Payment detail seam](payment-detail-seam.md) — GET /payment/{id} includes vs PaymentDetailPage; auth gate pattern (2026-06-28)
- [Unread badge seam](unread-badge-seam.md) — no dedicated endpoint; derived from Orion search meta.total; no orphaned frontend calls
- [Billing public seam](billing-public-seam.md) — Phase 1 catalog+quoter: contract aligned exact; CORS gap for landing prod origin (HIGH); custom routes ↔ Astro island (2026-06-30)
- [Billing checkout seam](billing-checkout-seam.md) — Phase 2 Slice 2: POST /billing/checkout {units,packages,interval}->{url}; 422 lacks code discriminator (MEDIUM); global 403 modal coupling; menu/entitlement projection no drift (2026-06-30)
- [Billing checkout Slice 3 UI seam](billing-checkout-slice3-seam.md) — Phase 2 Slice 3 React PlanBuilder: contract aligned field-for-field; Slice-2 MEDIUMs (error code + 403 modal) RESOLVED; no_billable_price readiness gap until Stripe sync (2026-06-30)
- [Tenant billing admin seam](tenant-billing-admin-seam.md) — Phase 2 Slice 5 super-admin TenantBillingModal (8 endpoints): TWO CRITICAL — registerPayment sends package_key vs backend billing_package_id; proration_date z.string vs backend int (card swap dead) (2026-06-30)
- [Billing provision+enforcement seam](billing-provision-enforcement-seam.md) — fase1: POST /tenant/provision (alta+billing), 403 SUBSCRIPTION_INACTIVE gate, SubscriptionStateService statuses. NO criticals; FE status union superset; base always sent (2026-06-30)
- [Billing self-serve multi-item seam](billing-selfserve-multiitem-seam.md) — fase1: GET /config/subscription multi_item + POST preview/add-addon/remove-addon/change-units/change-interval ↔ SubscriptionPage+manager+4 modals. ALIGNED, 0 criticals; LOW: change-units accepts-but-drops proration_date; FE shows message not code (2026-06-30)
- [Menu group seam](menu-group-seam.md) — GET /api/menu module.group (nullable string) for sidebar sub-grouping inside "Catálogos". ALIGNED, 0 criticals; top-level group intentionally unused by design (2026-07-02)
- [AmenityReservations Collections/Notification seam](amenity-reservations-collections-notification-seam.md) — S9-S11 cross-module review: surcharge FIFO isolation, payment_rejected scoping, expire() re-fetch pattern. 0 criticals, all tested green; S13 surcharges endpoint contract documented (2026-07-07)

## Recovered 2026-07-20 from stray `frontend/.claude/agent-memory` copy

- [Stack confirmation](stack-confirmation.md) — Laravel 13 + Stancl single-DB + React 19; reviewer is relevant
- [Pagination/filter contract](pagination-filter-contract.md) — Orion `POST /search` shape, filter operators, ISearchParams
- [Money contract](money-contract.md) — backend serializes MoneyIntegerCast as IMoney object; filters must send raw integers (minor units)
- [owner_ids param pattern](owner-ids-param-pattern.md) — custom top-level param outside filters[] for cross-model subquery filters; ISearchParams does not carry it
- [date shortcut timezone risk](date-shortcut-timezone-risk.md) — resolveDateRange uses browser-local Date; backend compares plain DATE columns
- [/selects mapper registry](charge-policy-selects-mapper.md) — useCatalogs keys hit POST /selects; type MUST be in AngularController::$mapperSelects or it 500s. charge_policy now FIXED.
- [ChargePolicy cockpit seam](charge-policy-cockpit-seam.md) — cockpit routes/includes/direct-payload/batch+charge filters all verified ALIGNED (2026-06-25)
- [Collections relation-key drift](collections-relation-key-drift.md) — 2 camelCase hasMany remain (chargeBatches read-exposed, paymentApplications latent); $snakeAttributes serialization reconfirmed; plan 034 fixed belongsTo set
- [Resident Invitation seam](resident-invitation-seam.md) — custom endpoints ALIGNED 2026-06-27; verify=top-level envelope, account_exists branch, auth-timed confirm, relationship UUID parity. DRIFT: FE INVITABLE_RELATIONSHIPS drops BILLING_CONTACT that BE accepts
- [Package Reception seam](package-reception-seam.md) — paquetería full envelope map; CRITICAL media 'package_photo'≠'photo' (photo never renders); WARN handover {count}vs{data:[]}, searchableBy lacks recipient_name, pickup_code-as-status. Module UNTRACKED (read files, no diff)
- [Tracking event seam (FB CAPI + GA4)](tracking-event-seam.md) — /api/track + checkout Purchase dedup ALIGNED; CRITICAL: AuthController::register double-fires CompleteRegistration+StartTrial to GA4 (and usually Facebook) because useSession.ts already fires them client-side and no event_id is threaded through /auth/register
- [Export Excel seam (/customReport)](export-excel-seam.md) — 15 model keys + 16 report.\* abilities ALIGNED; CRITICAL: body search/sort/owner_ids ignored + operator vocab mismatch (is_null/not_in dropped); blob errors lose JSON shape
