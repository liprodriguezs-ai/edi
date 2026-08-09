# React Frontend Reviewer — Memory

## Project: residia/monorepo (Kivo7)

- [react-frontend-architecture skill path](./react-frontend-architecture-skill-path.md) — Location of authoritative frontend skill
- [lint-baseline-2026-06](./lint-baseline-2026-06.md) — ESLint error/warning counts as of June 2026 audit
- [anti-pattern-violations](./anti-pattern-violations.md) — Known anti-pattern violations found in codebase
- [required-fields-form-convention](./anti-pattern-violations.md) — Enforcement rules (June 2026): `required` on FieldLabel + RequiredFieldsLegend at top of every data-entry form
- [architecture-observations](./architecture-observations.md) — Module structure and patterns observed
- [collections-form-page-migration](./collections-form-page-migration.md) — Concept→Policy split, Sheet→page form migration, recurring double-toast bug with useBaseCrud; merged parallel review adds the form-page canon details + ChargeBatchFormPage title-overwrite MAJOR bug + full-watch() perf smell
- [announcement-edit-capability](./announcement-edit-capability.md) — Edit mode added to compose modal; expires_at date format risk; double handleSubmit persists
- [package-catalog-admin-ui](./package-catalog-admin-ui.md) — WU-6 carrier/type CatalogCrudModule pages; order:null breaks CREATE (backend req missing nullable vs Tower)
- [package-module-review](./package-module-review.md) — feat/package-reception FINAL gate: build BROKEN (22 tsc errors); 5 root causes + reassign-dialog-never-enables-units, badges-always-blue, history-split-on-pickup_code
- [charge-batch-multiselect-rework](./charge-batch-multiselect-rework.md) — single→multiselect rework (target_unit_type_ids/target_tower_ids/target_feature_ids); selectorIds legacy fallback untested; radio-as-cards a11y correct; AmountMode/Periodicity hidden flag pattern
- [chat-scope-selector-unification](./architecture-observations.md) — ChannelTargetSection deleted; 2x SingleTargetSelector with READ/WRITE_TARGET_NAMES; board excluded from cobranza; stale JSDoc + eager useCatalogs (pre-existing) as open items
- [billing-fase1-review](./anti-pattern-violations.md) — TenantWizardPage `.uuid` type-escape bug + eager ROLE catalog; WizardPlanSelector quoteError silent; ManualPaymentSection mini-form label/id a11y gap
- [billing-v2-review](./anti-pattern-violations.md) — PlanPriceMatrix isMounted race condition; TenantList missing isDeleting props to renderRow; ChangeBillingPlanModal Label/useQuery issues; as string cast in TenantBillingPanel
- [sidebar-nav-subgroup-labels](./sidebar-nav-subgroup-labels.md) — groupNavSubItems sub-grouping by module `group`; "Otros" label collision risk (dup React key), untrimmed label bug, missing useMemo, li/span structure verified NOT broken
- [direct-charge-payment](./direct-charge-payment.md) — Cobro directo review: permission-gate mismatch (create.charge only, backend needs +create.payment), systemic UTC paid_at default bug (3 files), coerceAmount 0-vs-undefined message bug
- [amenity-reservation-pending-payment-gap](./amenity-reservation-pending-payment-gap.md) — Earmark "Pagar" buttons gate on amount_outstanding alone but pending payments don't change it → re-offered button hits backend one-pending 422 dead-end; resident cash-no-proof default compounds it
- [delinquency-restrictions-module](./delinquency-restrictions-module.md) — Per-unit gating verified holding; new modules/collections/ 1-hook module questioned; isLoading never consumed; BulkIntakeForm useWatch whole-array perf; guard-note a11y + trivially-passing disabled-button tests
- [delinquency-list-filters](./delinquency-list-filters.md) — error-state-masked-as-success (no global query onError; bare useQuery pages need isError), DelinquencyRow IEntity cast, pseudo-label spans in ad-hoc filter bars, single-debounced-params good pattern
- [complaint-module-review](./complaint-module-review.md) — tsc broken by test mock typing (vitest green ≠ build ok); ResponsiveDataTable onRowClick is cards-only → desktop TableRow needs own onClick; bare .max() = English Zod errors

## Recovered 2026-07-20 from stray `backend/.claude/agent-memory` copy

- [Project Conventions](./project-conventions.md) — Stack, toast system, date util, apiClient rules specific to this project
- [Checkout Subscription Module](./checkout-subscription-module.md) — Patterns and issues found in the Stripe checkout flow
- [Chat Admin Channels](./chat-admin-channels.md) — Fase 2 channel admin UI: patterns, dead code, ManualUserPicker labels bug, RadioGroup a11y gap
- [Tenant Billing Module](./tenant-billing-module.md) — Slice 5 super-admin per-tenant billing UI: expiration past-date bug, getLedger dead code, duplicated row extra-options, RadioGroup a11y done right
- [Board Period Module](./board-period-module.md) — member CRUD uses useMutation+refresh (not useBaseCrud); §7.3.2 required-roles invariant unguarded in single-member CRUD (front+back)
- [Amenity Reservations Module](./amenity-reservations-module.md) — S12-S16 gold-standard feature: schedule-x integration reference, fragile `as string` cast in RegisterReservationPaymentSheet, AmenityForm over max-lines, resident IDOR security note (backend)

## Recovered 2026-07-20 from stray `frontend/.claude/agent-memory` copy

- [Catalog Item Cast Pattern](./catalog-cast-pattern.md) — `(item as { name?: string }).name` is unnecessary; IEntity already has `name?`
- [Resource File Interface Placement](./resource-interface-placement.md) — Payload interfaces for custom actions belong in resource files, not schema.ts
- [Skeleton Import Source](./skeleton-import.md) — Project uses shadcn Skeleton for structural loading in headers/profile areas; boneyard-js for table/list loading states
- [new Date() For Input Defaults](./new-date-input-defaults.md) — `new Date().toISOString().slice(0,10)` for input default values is NOT a violation; only display formatting is banned
- [as never Cast on show()](./as-never-cast.md) — CommunityProjectDetailPage uses `as never` to pass include params to show() because the type param is TForm; recurring smell
- [Custom Action Pattern](./custom-action-pattern.md) — Non-CRUD actions = customMethods + apiClient inside createCrudApi (skill §2.4); no base helper exists; apiClient OK in services, banned in components
- [Responsive Data Tables Change](./responsive-data-tables.md) — ResponsiveDataTable<T> dual-branch CSS pattern; 7 migrated pages; BankAccountListPage has orphaned footer div (visual bug md+); generic aria-labels in card renderActions
- [ScanQr Camera Lifecycle](./scanqr-camera-lifecycle.md) — html5-qrcode stream leaks when stopCamera runs during a pending start() (isScanning still false); rAF/cancelled/reset-on-open patterns are sound
- Access Module Snake Case Migration (⚠️ detail file `access-snake-case.md` was never written — this summary line is ALL that exists) — relation-key drift fixed: entry_type→access_entry_type, motive→visitor_motive, status→visitor_status, verification→access_verification, createdByUser→created_by_user, blacklistType→blacklist_type; hoursInside function placed between imports (code smell but not a parse error in bundler); CorrectionModal submit button uses type="button" + onClick instead of native form submit; over-stay badge calls hoursInside twice (minor perf)
- [ChargePolicy Cockpit](./charge-policy-cockpit.md) — unified config+lotes+cargos screen; avoids as-never via Record<string,unknown> TForm; collapsed drill leaks "Abrir cobro" Link into tab order; dead stopPropagation in ChargePolicyListPage; 40 tests green
- [ChargeRule Form 2-col](./charge-rule-form-2col.md) — 2-col cockpit; form= save button outside form (OK); needsConceptPicker handles 3 cases (hidden Controller still validates); full watch() perf smell; vigencia nested ternary; eventual-edit preview dead
- [ChargeBatch Detail Architecture](./charge-batch-detail-architecture.md) — DetailPage is monolithic (no Detail split, no hooks/ files); BatchUnitChargesTable does inline useQuery inside components/; queryKey suffix 'stats' and toChargeRows non-array guard must be preserved on refactor
- [Resident Invitation Feature](./resident-invitation-feature.md) — all-custom non-Orion resource (plain apiClient object OK); per-unit TanStack keys; gating gap at UnitDetailPage:249 (PendingInvitationsList canManage hardcoded true); recurring catalog cast smell
- [Access Filter Feature](./access-filter-feature.md) — resolveDateRange only safe for DATE columns (not TIMESTAMP); qr_expires_at and created_at need `+ 23:59:59` on to-bound; useCatalogs inline object defeats useMemo chain; as any on BlacklistResource (fix: as unknown as); CatalogFormModal export is safe
- [Payment Actions Refactor](./payment-actions-refactor.md) — usePaymentActions closeReject/closeCancel don't clear reason on dismiss (stale contamination bug); renderActions useCallback is no-op (actions new object every render); invalidateQueries(['payments']) is dead code (detail uses ['payment',id])
- [Lazy Catalogs Refactor](./lazy-catalogs-refactor.md) — useLazyCatalogs + enabled:isOpen + display:contents filter wrappers; non-memoized request smell (charge/payment/unit) defeats options useMemo; MultiSelectFilter lacks onOpen → 5x duplicated wrapper (capture/bubble + missing `contents` inconsistencies); all form extractions verified submit/edit-safe
- [SearchSelect Rollout](./searchselect-rollout.md) — react-select v5 wrapper across 23 selects; coercion-by-optionality canon; isClearable-on-required UX change + react-select-in-Radix-modal QA risk; lazy improved (child-extraction) not regressed; MultiSelectFilter onFirstOpen added (fires every open, JSDoc says first); UnitListPage tower length-guard removed for lazy
- [MultiSelectFilter Searchable](./multiselectfilter-searchable.md) — client-side search Input added (backward-compat, value[] untouched, no selection-lost); UnitListPage 3 native Selects→MultiSelectFilter w/ Orion `in`; onFirstOpen misnomer (fires every open); search Input lacks aria-label
- [Tracking Analytics Consent Gap](./tracking-analytics-consent-gap.md) — new shared/analytics/ module: /track POST fires unconditionally (only fbq/gtag gated by consent), diverges from landing's trackEvent; fbclid captured but never wired; SuccessView Purchase can double-fire on refresh after intent consumed
- [Chat Channel Editor Page](./chat-channel-editor-page.md) — modal→page canon OK; editor routes read-gated (gap shared w/ announcement); missing ['chat','channel',id] invalidation; identical-queryKey cache sharing is deliberate
- [Export Excel Lists](./export-excel-lists.md) — hook-local isExporting (not putLoading); onReport rethrows → unhandled rejection at 15 bare call sites; 14x duplicated Descargar block wants ExportButton molecule; limit/page sent to /customReport
