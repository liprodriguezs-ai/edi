# Tasks: Cluster Onboarding Checklist

Change: `cluster-onboarding-checklist`
Delivery: `single-pr` — `size:exception` recorded (estimated ~550 lines across 12 files)
Artifact store: hybrid (engram + openspec)

---

## Execution order

Backend (Phase 1) must land before the frontend data layer (Phase 2) that calls it.
Phase 2 (data layer) must exist before Phase 3 (UI) and Phase 4 (routing).
Phase 3 and Phase 4 can be implemented in parallel once Phase 2 is done.
Phase 5 (verification) runs last.

---

## Phase 1 — Backend API

> Sequential within this phase (service → controller → route → test).
> Satisfies spec requirements A (endpoint) and B (predicates).

- [ ] **1.1** Create `backend/Modules/Dashboard/app/Services/OnboardingChecklistService.php`
  - Ordered item registry (array of 9 items, each with id/title/description/details/route/required/predicate-closure)
  - `checklist(User $user): array` — admin short-circuit first (`hasAnyRole(['administrator','account.owner'])` → if false return `{items:[],required_total:0,required_completed:0,progress_percentage:100,is_complete:true}`); then evaluate each predicate via `exists()`, assemble items, compute `required_total=8`, `required_completed`, `progress_percentage` (0–100), `is_complete` (all 8 required done)
  - Predicates (use RESOLVED values from design — do not re-open):
    1. `company_identity`: `Company::query()->main()->whereNotNull('legal_name')->where('legal_name','!=','')->whereNotNull('rfc')->where('rfc','!=','')->exists()`
    2. `collection_params`: `Setting::query()->whereIn('slug',['collections.default_due_day','collections.default_grace_days'])->whereNotNull('value')->where('value','!=','')->exists()`
    3. `towers_sections`: `Tower::query()->exists() || Section::query()->exists()`
    4. `units`: `Unit::query()->exists()`
    5. `residents`: `UnitUser::query()->exists()`
    6. `board_treasurer`: `BoardPeriod::query()->where('board_period_status_id',BoardPeriodStatus::CURRENT)->whereHas('members', fn($q)=>$q->where('board_role_id',BoardRole::TREASURER))->exists()`
    7. `bank_account`: `BankAccount::query()->exists()`
    8. `charge_concepts`: `ChargeConcept::query()->exists()`
    9. `catalogs` (optional, `required:false`): `UnitType::query()->exists()`
  - Item `count` field: 0 when not completed, otherwise `Model::query()->count()` for simple-existence items (omit for compound items — board_treasurer/company_identity)
  - Imports: `Modules\Company\Models\Company`, `Modules\Setting\Models\Setting`, `Modules\Housing\Models\{Tower,Section,Unit,UnitUser,UnitType}`, `Modules\Board\Models\{BoardPeriod,BoardMember,BoardPeriodStatus,BoardRole}`, `Modules\Treasury\Models\BankAccount`, `Modules\Collections\Models\ChargeConcept`, `App\Models\User`

- [ ] **1.2** Create `backend/Modules/Dashboard/app/Http/Controllers/OnboardingChecklistController.php`
  - Extends `Illuminate\Routing\Controller` (plain non-Orion, same pattern as `DashboardController`)
  - `index(Request $request): JsonResponse` — inject `OnboardingChecklistService`, call `$service->checklist($request->user())`, return `response()->json(['data' => $result])`
  - No FormRequest (GET with no body params)

- [ ] **1.3** Edit `backend/Modules/Dashboard/routes/api.php`
  - Inside the existing `Route::middleware(['throttle:api','auth:sanctum','initializeTenancy'])->prefix('dashboard')->group(...)` block
  - Add: `Route::get('onboarding-checklist', [OnboardingChecklistController::class, 'index']);`
  - Add `use Modules\Dashboard\Http\Controllers\OnboardingChecklistController;` at the top

- [ ] **1.4** Create `backend/Modules/Dashboard/tests/Feature/OnboardingChecklistTest.php`
  - Test class `OnboardingChecklistTest extends TestCase` using `RefreshDatabase` + Sanctum
  - Test cases (each a separate test method):
    - `test_unauthenticated_returns_401`: GET without token → 401
    - `test_new_tenant_admin_sees_all_items_pending`: fresh tenant + admin user → `required_completed:0`, `is_complete:false`, `items` has 9 entries, all `completed:false`
    - `test_company_identity_predicate`: set Company with legal_name+rfc → item `company_identity.completed:true`
    - `test_collection_params_predicate`: seed Setting row for `collections.default_due_day` → item `collection_params.completed:true`
    - `test_towers_sections_predicate`: create Tower → item `towers_sections.completed:true`
    - `test_units_predicate`: create Unit → `units.completed:true`
    - `test_residents_predicate`: create UnitUser → `residents.completed:true`
    - `test_board_treasurer_predicate`: create open BoardPeriod + BoardMember with TREASURER role → `board_treasurer.completed:true`; open period with no treasurer → `completed:false`
    - `test_bank_account_predicate`: create BankAccount → `bank_account.completed:true`
    - `test_charge_concepts_predicate`: create ChargeConcept → `charge_concepts.completed:true`
    - `test_is_complete_when_all_required_done`: fulfill all 8 required items → `is_complete:true`, optional `catalogs` still pending does not block
    - `test_non_admin_gets_short_circuit`: resident role user → `items:[]`, `is_complete:true`
    - `test_tenant_isolation`: data belonging to another tenant → predicates return false for current tenant
  - Run: `php artisan test --filter=OnboardingChecklist` then `vendor/bin/pint --dirty`

---

## Phase 2 — Frontend Data Layer

> Sequential within phase (schema → resource → hook). Parallel with nothing; must complete before Phases 3 & 4.
> Satisfies spec requirements A (response contract).

- [ ] **2.1** Create `frontend/src/modules/onboarding/schema.ts`
  - `IOnboardingItem` extends `IOnboardingStep` (imported from `@/modules/dashboard/schema`) + `required: boolean`, `details?: string`, `count?: number`
  - `IOnboardingChecklist { items: IOnboardingItem[]; required_total: number; required_completed: number; progress_percentage: number; is_complete: boolean }`
  - Export both interfaces

- [ ] **2.2** Create `frontend/src/modules/onboarding/services/OnboardingResource.ts`
  - Mirror `DashboardResource` pattern: `import { apiClient } from '@/shared/api/apiClient'`
  - `OnboardingResource = { getChecklist: async (): Promise<IOnboardingChecklist> => { const { data } = await apiClient.get<{ data: IOnboardingChecklist }>('/dashboard/onboarding-checklist'); return data.data } }`

- [ ] **2.3** Create `frontend/src/modules/onboarding/hooks/useOnboardingChecklist.ts`
  - `useQuery` with key `['onboarding-checklist']`, `staleTime: 5 * 60 * 1000` (5 min)
  - `enabled`: only when user has admin role — use `useRoles()` helpers: `hasAnyRole([ERoleUserSlug.ADMINISTRATOR, ERoleUserSlug.ACCOUNT_OWNER])`
  - Returns `{ data, isLoading, isError }` (expose raw query result)
  - Query fn: `OnboardingResource.getChecklist`

---

## Phase 3 — Frontend UI

> Can proceed in parallel with Phase 4 once Phase 2 is done.
> Satisfies spec requirements C (onboarding page) and D (progress indicator).

- [ ] **3.1** Create `frontend/src/modules/onboarding/OnboardingPage.tsx`
  - Import: `useOnboardingChecklist`, `IOnboardingItem`, `RouteFallback`, shadcn `Badge`, `Link` from react-router-dom, `Skeleton` from `boneyard-js/react`
  - While loading → `<RouteFallback />`
  - Page layout: `PageHeader` (if exists in project) or plain `<h1>` "Configura tu clúster" + paragraph subtitle
  - Progress bar: inline Tailwind `div` (`<div class="h-2 rounded-full bg-primary/20"><div class="h-2 rounded-full bg-primary transition-all" style={{width: `${data.progress_percentage}%`}} /></div>`), label `X / 8 completados`
  - Required items list: map `items.filter(i => i.required)` → per item: link wrapper (`<Link to={item.route}>`), item title + details text, `<Badge>` "Pendiente" (muted) / "Completado" (primary) + count if `> 0`
  - Optional section: `items.filter(i => !i.required)` separated with a `<Separator />` and label "Recomendado"
  - Success state: when `is_complete === true` → replace list with success card + `<Link to="/dashboard">Ir al panel</Link>`
  - Skeleton: wrap card area in `<Skeleton name="onboarding-page" loading={isLoading}>...</Skeleton>`

- [ ] **3.2** Create `frontend/src/modules/onboarding/components/OnboardingProgressPill.tsx`
  - Import: `useOnboardingChecklist`, `useRoles`, `ERoleUserSlug`, `Link` from react-router-dom
  - Guard: render `null` if non-admin OR `data?.is_complete === true` OR `isLoading`
  - UI: compact pill `<Link to="/onboarding">` with `ChecklistIcon` (or `ClipboardList` from lucide-react) + text `"Configuración X/8"` or `required_completed/required_total`
  - Tailwind: `rounded-full border border-border px-3 py-1 text-xs font-medium text-muted-foreground hover:text-foreground transition-colors`

- [ ] **3.3** Edit `frontend/src/modules/dashboard/DashboardPage.tsx`
  - Import `useOnboardingChecklist` and `OnboardingCard`
  - At the top of `DashboardPage` component body: call `useOnboardingChecklist()`, get `data`
  - Map `data?.items` to `IOnboardingStep[]` compatible shape (drop `required`/`details`, pass `id/title/description/completed/route`)
  - Render `<OnboardingCard steps={mappedSteps} isDismissedFromServer={data?.is_complete ?? false} onDismiss={async () => {}} />` above `<TreasurerHome />` when `data && !data.is_complete`
  - `onDismiss`: no-op for now (no dismiss endpoint in this change per spec Non-Goals); keep the `async` signature

---

## Phase 4 — Routing and Soft Landing

> Can proceed in parallel with Phase 3 once Phase 2 is done.
> Satisfies spec requirements E (soft landing) and C (route guard for /onboarding).

- [ ] **4.1** Create `frontend/src/app/router/HomeRedirect.tsx`
  - Import: `useOnboardingChecklist`, `useRoles`, `ERoleUserSlug`, `roleRoutes`, `Navigate`, `RouteFallback`
  - Logic:
    1. `const { hasAnyRole } = useRoles()`
    2. `const isAdmin = hasAnyRole([ERoleUserSlug.ADMINISTRATOR, ERoleUserSlug.ACCOUNT_OWNER])`
    3. If not admin → `<Navigate to={roleRoutes[role] ?? '/dashboard'} replace />`
    4. Call `useOnboardingChecklist()` (query already `enabled` by admin check internally, but call unconditionally here since guard above ensures admin context)
    5. If `isLoading` → `<RouteFallback />`
    6. If `data?.is_complete === false` → `<Navigate to="/onboarding" replace />`
    7. Else → `<Navigate to="/dashboard" replace />`
  - Note: `useSession` provides `role` (single primary role string); use it for the non-admin fallback path
  - Do NOT use `useMemo` for the guard logic — keep it flat and readable

- [ ] **4.2** Edit `frontend/src/App.tsx`
  - Add lazy import: `const OnboardingPage = lazy(() => import('@/modules/onboarding/OnboardingPage').then(m => ({ default: m.OnboardingPage })))`
  - Add non-lazy import: `import { HomeRedirect } from '@/app/router/HomeRedirect'`
  - Replace `<Route path="/" element={<Navigate to={roleRoute ?? '/dashboard'} replace />}` with `<Route path="/" element={<HomeRedirect />} />`
  - Remove the `roleRoute` / `useMemo` block if no longer used elsewhere (check — it may still be needed elsewhere in the file; remove only if unused)
  - Add `/onboarding` route inside `SubscriptionGuard > DashboardLayout`:
    ```
    <Route element={<PermissionRoute role={[ERoleUserSlug.ADMINISTRATOR, ERoleUserSlug.ACCOUNT_OWNER]} />}>
      <Route path="/onboarding" element={<OnboardingPage />} />
    </Route>
    ```
  - Add `onboarding` to `SECTION_LABELS` in `site-header.tsx` (handled in task 4.4)

- [ ] **4.3** Edit `frontend/src/components/login-form.tsx`
  - In `handleLoginSubmit`, change `navigate(role ? roleRoutes[role] : '/', { replace: true })` to `navigate('/', { replace: true })`
  - Remove import of `roleRoutes` if it becomes unused after this change (verify first)

- [ ] **4.4** Edit `frontend/src/components/site-header.tsx`
  - Add `onboarding: 'Configuración inicial'` to `SECTION_LABELS`
  - Import `OnboardingProgressPill` from `@/modules/onboarding/components/OnboardingProgressPill`
  - Mount `<OnboardingProgressPill />` between the search button cluster and `<NotificationBell />` (i.e., just before `<NotificationBell />`)

---

## Phase 5 — Verification

> Sequential final phase; all previous phases must be complete.

- [ ] **5.1** Backend lint + tests
  - `cd backend && vendor/bin/pint --dirty`
  - `php artisan test --filter=OnboardingChecklist`
  - All 13 test cases pass

- [ ] **5.2** Frontend lint, types, tests
  - `cd frontend && pnpm lint`
  - `pnpm typecheck` (or `pnpm tsc --noEmit` if no typecheck script)
  - `pnpm test` (if Vitest unit tests exist or are added for `useOnboardingChecklist` and `HomeRedirect`)

- [ ] **5.3** Manual E2E — spec scenarios
  - Admin with fresh tenant → logs in → redirected to `/onboarding`, all items Pendiente
  - Admin clicks item row link (e.g. `/configuration`) → sets legal_name+rfc → navigates back → item flips to Completado
  - All 8 required items complete → pill in header disappears, banner on dashboard disappears, page shows success state
  - Non-admin (Resident role) → logs in → goes to `/my-unit`, not `/onboarding`
  - Non-admin navigates to `/onboarding` directly → redirected to `/acceso-denegado`

---

## Parallel execution map

```
Phase 1 (1.1 → 1.2 → 1.3 → 1.4)   [sequential]
         ↓
Phase 2 (2.1 → 2.2 → 2.3)           [sequential]
         ↓
    ┌────┴────┐
Phase 3    Phase 4                   [parallel]
    └────┬────┘
         ↓
Phase 5 (5.1 → 5.2 → 5.3)           [sequential]
```

Total: 17 tasks across 5 phases.

---

## Review Workload Forecast

| Metric | Estimate |
|--------|----------|
| New files (backend) | 2 (Service, Controller) |
| New files (frontend) | 5 (schema, resource, hook, OnboardingPage, OnboardingProgressPill, HomeRedirect) |
| Modified files | 4 (routes/api.php, App.tsx, login-form.tsx, site-header.tsx, DashboardPage.tsx) |
| Total files touched | ~12 |
| Estimated new lines (backend) | ~130 (service ~80, controller ~20, route ~5, test ~130 → 235 total for backend) |
| Estimated new lines (frontend) | ~320 (schema ~15, resource ~15, hook ~25, OnboardingPage ~120, Pill ~35, HomeRedirect ~40, App.tsx edits ~15, login-form ~3, site-header ~10, DashboardPage ~20) |
| Total estimated lines | ~555 |
| Exceeds 400-line budget | Yes |
| Chained PRs recommended | No — single-pr already chosen by user |
| Decision | `size:exception` recorded; proceed as one PR |
