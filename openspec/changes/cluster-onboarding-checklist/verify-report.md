# Verify Report — cluster-onboarding-checklist

**Verdict:** pass-with-warnings
**Date:** 2026-06-14
**Reviewer:** sdd-verify (fresh adversarial)
**Summary:** 1 CRITICAL, 3 WARNING, 3 SUGGESTION. All automated gates green.

---

## Gate Results (verbatim)

### Backend — tests
`php artisan test Modules/Dashboard/tests/Feature/OnboardingChecklistTest.php`
```
PASS  Modules\Dashboard\Tests\Feature\OnboardingChecklistTest
✓ unauthenticated request returns 401
✓ brand new tenant has all required items pending
✓ legal branding completed when company has legal name and rfc
✓ legal branding pending when rfc is missing
✓ collection params completed when settings exist
✓ towers sections completed when tower exists
✓ units completed when unit exists
✓ residents completed when unit user exists
✓ board treasurer completed when current period has treasurer
✓ board treasurer pending when period has no treasurer
✓ bank account completed when bank account exists
✓ charge concepts completed when charge concept exists
✓ optional catalogs item does not block is complete
✓ is complete true when all required items are done
✓ non admin user receives empty complete response
✓ account owner role receives full checklist
✓ data from another tenant does not count
✓ response has expected structure

Tests:    18 passed (134 assertions)
Duration: 33.38s
```

> NOTE: `php artisan test --filter=Onboarding` is the WRONG command — it matches
> `Tests\Feature\Auth\OnboardingCompletionTest` (2 tests, the system-bootstrap
> onboarding) because not all checklist method names contain "Onboarding". Run
> the new suite by file path.

### Backend — Pint
`vendor/bin/pint --test Modules/Dashboard`
```
{"tool":"pint","result":"passed"}
```

Route registered:
```
GET|HEAD  api/dashboard/onboarding-checklist
GET|HEAD  api/dashboard/summary
```

### Frontend — typecheck
`pnpm typecheck` → `tsc -p tsconfig.app.json --noEmit` → 0 errors.

### Frontend — lint
`pnpm lint` → 6 errors + 28 warnings, **ALL in pre-existing unrelated files**
(accept-invitation-form, ui/sidebar, ui/badge, ui/button, ui/tabs, access modals,
boardPeriod/StartPeriodModal, chargeBatch/ChargeBatchFormModal, expense/ExpenseFormModal,
payment sheets, unit modals, SessionProvider, ChangePasswordModal, ProfilePhotoModal,
SearchList, CatalogCrudModule, DateRangeFilter, CrudMode, useCatalogs).
**ZERO lint issues in any change-set file** (onboarding/*, HomeRedirect, App.tsx,
login-form, site-header, DashboardPage). Verified by cross-referencing the unique
flagged-file list against the touched files.

### Frontend — tests
`pnpm test` → 16 files, 103 passed. None cover the onboarding feature
(no test references `onboarding` / `HomeRedirect`).

---

## Findings

### CRITICAL
- **C1 — `/onboarding` route is not admin-gated.** `frontend/src/App.tsx:148`
  registers `<Route path="/onboarding" element={<OnboardingPage />} />` with NO
  `<PermissionRoute role={[ERoleUserSlug.ADMINISTRATOR, ERoleUserSlug.ACCOUNT_OWNER]}>`
  wrapper. Spec Req C ("Route gated to Administrator / Account Owner only") and the
  design both require it. As-is, any authenticated user can navigate to
  `/onboarding`; spec scenario "Non-admin visiting /onboarding → redirected"
  FAILS. The backend short-circuit prevents data leakage (non-admin gets the empty
  payload and the page renders `null`), but the route-guard layer of the intended
  3-layer defense is absent. **Fix:** wrap the route in a `PermissionRoute` role
  guard, matching the design.

### WARNING
- **W1 — Hook is not the designed shared TanStack Query.** Design specified one
  shared `['onboarding-checklist']` query (staleTime 5min) → a single network call
  across pill/banner/redirect. `useOnboardingChecklist` instead uses per-instance
  `useState` + `useInitOnce` (whose `_key` is ignored), so each consumer fires its
  own request. On `/dashboard` the header pill AND the dashboard banner both mount
  → 2 independent GETs per visit. Functionally correct (backend idempotent) but
  contradicts the explicit design decision and the apply-progress "single shared
  call" claim.
- **W2 — Hook has no admin `enabled` gating.** Design layer 2 of defense-in-depth
  was a query `enabled` gate for non-admins. The hook fetches unconditionally on
  mount; every non-admin (resident/security) hits the endpoint on any page that
  mounts the pill (site-header = all dashboard pages). Backend short-circuits
  safely, so no leak — but the front gate is missing.
- **W3 — Dashboard banner does not reuse `OnboardingCard`.** Design said REUSE
  `OnboardingCard` + `IOnboardingStep`; implementation built a new inline
  `OnboardingBanner` in `DashboardPage.tsx`. The spec behavioral requirement
  (banner only when admin && !is_complete) IS met, but the `OnboardingCard`
  props the verify prompt referenced (`isDismissedFromServer` / `onDismiss`) are
  NOT exercised — `OnboardingCard` is untouched and fed nothing. No regression.
- (related) No frontend tests delivered for the feature, though the design Testing
  section called for Vitest coverage of `useOnboardingChecklist` (non-admin
  disabled), `HomeRedirect` branches, and pill/banner hiding. `tasks.md` did not
  enumerate FE test tasks, so this is informational.

### SUGGESTION
- **S1 — `count` hardcoded to 0.** `item()` always sets `count: 0`. Design says
  "count omitted / 0"; spec Req A says count = quantity when completed. Resolved
  toward design. The frontend never displays count, so harmless — but align the
  spec or drop the field to avoid confusion.
- **S2 — HomeRedirect role pick.** Uses `roles[0]` for the role-route lookup; with
  multiple roles the first wins. Matches existing app convention but is
  order-dependent.
- **S3 — Unknown-path fallback.** `* → / → HomeRedirect` sends an incomplete admin
  to `/onboarding` on any 404. Acceptable (admin is not trapped on real routes).

---

## Verified Safe / Conformant
- **Tenant isolation:** `Company::scopeMain()` adds `where('tenant_id', current)`
  when tenancy is initialized (the endpoint runs under `initializeTenancy`), so
  the one CENTRAL model is correctly scoped — no cross-tenant cluster-identity
  leak. All 9 predicate models carry `BelongsToTenant`; `BoardMember` is reached
  through the tenant-scoped `BoardPeriod`. `test_data_from_another_tenant_does_not_count`
  passes.
- **No N+1:** every predicate uses `->exists()` (LIMIT 1); `board_treasurer` is a
  single EXISTS subquery via `whereHas('members')`.
- **Non-admin short-circuit:** returns
  `{items:[], required_total:0, required_completed:0, progress_percentage:100, is_complete:true}`
  (impl/test/design agree on `required_total:0`; the spec prose's `8` is the only
  divergence — impl is internally consistent and the test asserts `0`).
- **API shape, 8 required + 1 optional, routes, is_complete = all required done
  (optional excluded), login → '/', HomeRedirect branches, pill/banner gated by
  admin && !is_complete** — all conform.
- **System-bootstrap `app/Http/Controllers/OnboardingController.php` NOT modified**
  (Non-Goal respected).
- **Conventions:** no `any` in new TS; UI strings in Spanish; English identifiers;
  OnboardingPage accessibility sound (status conveyed by icon shape + strikethrough,
  not color alone; `role="progressbar"` with aria-value*; `<Link>` keyboard-reachable;
  decorative icons `aria-hidden`).
- **No regression** to `/dashboard/summary` or `DashboardResource`.

---

## Next Recommended
`sdd-apply` to fix **C1** (add the `PermissionRoute` role guard on `/onboarding`);
optionally address **W1/W2** (shared query + `enabled` gating). After C1 is
resolved → `sdd-archive`.
