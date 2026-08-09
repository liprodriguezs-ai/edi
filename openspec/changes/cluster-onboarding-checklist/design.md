# Design: Cluster Onboarding Checklist

## Technical Approach

A dedicated, data-derived onboarding checklist. The backend exposes ONE read endpoint
(`GET /api/dashboard/onboarding-checklist`) backed by a new `OnboardingChecklistService`
that holds an ordered item registry; each item's `completed` flag is DERIVED at request
time via a tenant-scoped `Model::query()->exists()` predicate (no new table, no stored
state). The frontend adds a small `onboarding` module that consumes the endpoint through a
single shared TanStack Query key, rendering a full page, a dashboard banner (reusing
`OnboardingCard`), a header pill, and a soft-landing `HomeRedirect`. Admin-only on three
layers (route guard + query `enabled` + backend short-circuit).

## Architecture Decisions

| Decision | Choice | Alternatives rejected | Rationale |
|---|---|---|---|
| Endpoint | Dedicated `GET /dashboard/onboarding-checklist` | Fold into `/dashboard/summary` | Summary is for KPIs; checklist has its own admin gating, cache lifetime, and consumers (pill/banner/redirect). Mixing forces all of them to refetch the heavy summary. |
| Completion source | Derived per-request via `exists()` | Stored `onboarding_step` rows / dismiss flags | Zero migration, self-healing (deleting data un-completes a step), no sync bugs. The unused `user.onboarding_checklist_dismissed` column stays unused. |
| Per-item probe | `Model::query()->exists()` | `->count()` | `exists()` stops at the first row (`LIMIT 1`); `count()` scans. We only need a boolean. `count` is omitted from the payload to avoid N full counts. |
| Admin detection | Spatie `hasAnyRole(['administrator','account.owner'])` | Token abilities / `dashboard` permission | Roles are the product concept of "cluster admin". Non-admins get a short-circuited complete payload. |
| Admin gating | Route guard + query `enabled` + backend short-circuit | Single layer | Defense in depth: guard hides the route, `enabled:false` avoids the fetch for non-admins, backend never leaks the list. |
| Tenancy | Rely on `BelongsToTenant` auto-scope under `initializeTenancy` | Manual `where('tenant_id', …)` | All probed models (Tower, Section, Unit, UnitUser, BoardPeriod, BoardMember, BankAccount, ChargeConcept) use `BelongsToTenant`. ONLY `Company` is central → must use its `scopeMain()` (it tenant-filters when tenancy is initialized). `Setting` is `BelongsToTenant`, auto-scoped. |
| Header pill / banner / redirect data | One shared query key `['onboarding-checklist']` | Independent fetches per consumer | TanStack dedupes by key; all three read the same cached result → one network call. `staleTime: 5min`. |

## Data Flow

    login-form ──navigate("/")──▶ HomeRedirect ──useOnboardingChecklist (admin only)
                                       │  loading → <RouteFallback/>
                                       │  incomplete → <Navigate to="/onboarding"/>
                                       └  complete/non-admin → roleRoute (/dashboard)

    SiteHeader (pill) ┐
    DashboardPage (banner via OnboardingCard) ┼─ useOnboardingChecklist ─▶ queryKey ['onboarding-checklist']
    OnboardingPage ┘                          (single cached GET /dashboard/onboarding-checklist)

    Backend: Controller@index → OnboardingChecklistService::checklist()
             → [item.predicate() per item] → aggregates → response()->json(['data'=>...])

## File Changes

| File | Action | Description |
|---|---|---|
| `backend/Modules/Dashboard/app/Services/OnboardingChecklistService.php` | Create | Ordered item registry + `checklist()` assembling items + aggregates; non-admin short-circuit. |
| `backend/Modules/Dashboard/app/Http/Controllers/OnboardingChecklistController.php` | Create | `index()` → `response()->json(['data' => $service->checklist($user)])`. Plain JSON (non-Orion). |
| `backend/Modules/Dashboard/routes/api.php` | Modify | Add `Route::get('onboarding-checklist', [OnboardingChecklistController::class,'index'])` inside existing `['throttle:api','auth:sanctum','initializeTenancy']` + `prefix('dashboard')` group. |
| `frontend/src/modules/onboarding/schema.ts` | Create | `IOnboardingItem` (extends `IOnboardingStep` + `required`, `details?`, `count?`), `IOnboardingChecklist`. |
| `frontend/src/modules/onboarding/services/OnboardingResource.ts` | Create | Single `getChecklist()` GET, mirrors `DashboardResource`. |
| `frontend/src/modules/onboarding/hooks/useOnboardingChecklist.ts` | Create | TanStack Query, key `['onboarding-checklist']`, `enabled` = admin, `staleTime` 5min. |
| `frontend/src/modules/onboarding/OnboardingPage.tsx` | Create | Full-page list + inline Tailwind progress bar; skeleton via boneyard-js. |
| `frontend/src/modules/onboarding/components/OnboardingProgressPill.tsx` | Create | Header pill; hidden when complete or non-admin; click → `/onboarding`. |
| `frontend/src/app/router/HomeRedirect.tsx` | Create | Soft landing: non-admin → roleRoute; admin → checklist → `/onboarding` or `/dashboard`; `RouteFallback` while loading. |
| `frontend/src/App.tsx` | Modify | Lazy import `OnboardingPage`; `/` → `<HomeRedirect/>`; add `/onboarding` under `PermissionRoute role={[ADMINISTRATOR, ACCOUNT_OWNER]}`. |
| `frontend/src/components/login-form.tsx` | Modify | `navigate('/', { replace:true })` (let HomeRedirect decide) instead of direct `roleRoutes[role]`. |
| `frontend/src/components/site-header.tsx` | Modify | Mount `<OnboardingProgressPill/>` near the search/bell cluster. |
| `frontend/src/modules/dashboard/DashboardPage.tsx` | Modify | Mount the banner: `OnboardingCard` fed by mapped checklist items. |

## Interfaces / Contracts

Response (`200`): `{ "data": { items, required_total, required_completed, progress_percentage, is_complete } }`
where each item = `{ id, title, description, details, route, required, completed }`.

```php
// OnboardingChecklistService — predicate per item (closure returning bool)
'company_identity'  => fn () => Company::query()->main()
                          ->whereNotNull('legal_name')->where('legal_name','!=','')
                          ->whereNotNull('rfc')->where('rfc','!=','')->exists(),
'collection_params' => fn () => Setting::query()
                          ->whereIn('slug', ['collections.default_due_day','collections.default_grace_days'])
                          ->whereNotNull('value')->where('value','!=','')->exists(),
'towers_sections'   => fn () => Tower::query()->exists() || Section::query()->exists(),
'units'             => fn () => Unit::query()->exists(),
'residents'         => fn () => UnitUser::query()->exists(),
'board_treasurer'   => fn () => BoardPeriod::query()
                          ->where('board_period_status_id', BoardPeriodStatus::CURRENT)
                          ->whereHas('members', fn ($q) => $q->where('board_role_id', BoardRole::TREASURER))
                          ->exists(),
'bank_account'      => fn () => BankAccount::query()->exists(),
'charge_concepts'   => fn () => ChargeConcept::query()->exists(),
'catalogs'          => fn () => UnitType::query()->exists(),   // optional, required:false
```

```typescript
export interface IOnboardingItem extends IOnboardingStep { required: boolean; details?: string; count?: number }
export interface IOnboardingChecklist {
  items: IOnboardingItem[]
  required_total: number; required_completed: number
  progress_percentage: number; is_complete: boolean
}
```

Admin short-circuit: `if (! $user?->hasAnyRole(['administrator','account.owner'])) return ['items'=>[], 'required_total'=>0, 'required_completed'=>0, 'progress_percentage'=>100, 'is_complete'=>true];`

## Testing Strategy

| Layer | What to Test | Approach |
|---|---|---|
| Unit (PHPUnit) | Each predicate true/false; aggregates; non-admin short-circuit; tenant isolation (item false when only another tenant has data) | Feature test seeding per-tenant rows, asserting JSON `data`. |
| Integration | Endpoint auth (401), throttle, role gating | HTTP feature test with Sanctum token. |
| Frontend | `useOnboardingChecklist` disabled for non-admin; HomeRedirect branches; pill/banner hide when complete | Vitest + Testing Library, mocked resource. |

## Migration / Rollout

No migration required — completion is derived. No data backfill. Feature is additive
(new endpoint + new module + soft landing). Rollback = revert the App.tsx/login-form edits;
the endpoint is harmless if unused.

## Open Questions (RESOLVED)

- Item 1 predicate → `Company::main()` with non-empty `legal_name` AND `rfc`.
- Item 2 predicate → a non-empty `Setting` row for `collections.default_due_day` OR
  `collections.default_grace_days` (admin-touched collection params; Tenant timezone/currency
  carry seeded defaults so they are NOT reliable signals — Setting rows only exist once saved).
- Item 6 predicate → a CURRENT `BoardPeriod` (`board_period_status_id = BoardPeriodStatus::CURRENT`)
  that has a `BoardMember` with `board_role_id = BoardRole::TREASURER`.
