# Change Proposal: cluster-onboarding-checklist

- **Change name:** `cluster-onboarding-checklist`
- **Project:** residia-monorepo (Kivo7)
- **Status:** proposed
- **Artifact store:** hybrid (engram + openspec)
- **Delivery strategy:** single-pr (size:exception to be registered at apply)

---

## Why

When a cluster administrator finishes creating their account, they land on `/dashboard`
with **zero guidance**. Kivo7 requires a strict, dependency-ordered chain of data to
exist before the platform can do its core job (generating charges and billing residents):
the cluster's legal/branding identity, its collection parameters, its physical structure
(towers/sections/units), its residents, an active board period with a treasurer, a bank
account, and at least one charge concept. Today nothing tells the admin **what** to register
or **in what order**.

The result is a cold-start problem: a new admin doesn't know where to begin, registers data
out of order (e.g. tries to create units before towers exist), or simply abandons setup.
The business need is a **soft, self-explanatory onboarding** that turns the implicit
dependency chain into an explicit, always-visible checklist — without ever hard-blocking the
admin from exploring the product freely.

A disconnected skeleton already exists in the codebase but is wired to nothing:
- Frontend `IOnboardingStep { id, title, description, completed, route }` and a complete
  `OnboardingCard` component (dismissible checklist with ✓/○, description, per-item link) —
  **not mounted anywhere**.
- Backend `DashboardService::onboardingSteps()` and `summary()` are **stubs returning `[]`**.

This change connects and completes that skeleton with real, data-derived completion.

---

## What changes

This change ADDS an onboarding capability and MODIFIES the post-login landing behavior.

### ADDED — Onboarding checklist API (backend)
- New `OnboardingChecklistService` + `OnboardingChecklistController` in the **Dashboard** module.
- New endpoint `GET /api/dashboard/onboarding-checklist`, registered in the group
  `['throttle:api', 'auth:sanctum', 'initializeTenancy']`.
- Returns the 8 required + 1 optional checklist items, each with `id`, `title`,
  `description` (WHAT IT IS FOR), `route`, and a `completed` flag, plus an aggregate
  `is_complete` (true when all REQUIRED items are done).
- `completed` is **DERIVED from real tenant data** via `Model::query()->exists()` / `->count()`
  on tenant-scoped models (auto-scoped under `initializeTenancy`). There is no manual "done"
  flag and **no migration**.
- Non-admin roles receive an empty / already-complete response (the checklist is an
  admin-only concern).

### ADDED — Onboarding page + module (frontend)
- New module `frontend/src/modules/onboarding/`: `schema.ts`, `OnboardingResource`,
  `useOnboardingChecklist` hook, `OnboardingPage`, `OnboardingProgressPill`.
- `OnboardingPage` lists each item with its purpose, done/not-done state, and a direct
  LINK to go register it. Reuses the existing `IOnboardingStep` shape.

### ADDED — Persistent progress indicator
- Header **pill** (mounted in `site-header.tsx`) and a dashboard **banner** (the existing
  `OnboardingCard`, mounted in `DashboardPage.tsx`), both shown while the checklist is
  incomplete. Clicking either returns the admin to `/onboarding`.

### MODIFIED — Soft landing after account creation / login
- New `HomeRedirect` mounted at `/`: routes admins with an incomplete checklist to
  `/onboarding`, everyone else to `/dashboard`. Nothing is hard-blocked — the admin can
  navigate away freely at any time.
- `App.tsx`: add the `/onboarding` route gated to `ADMINISTRATOR` / `ACCOUNT_OWNER`, and
  point `/` to `HomeRedirect`.
- `login-form.tsx`: navigate to `/` (instead of straight to `/dashboard`) so `HomeRedirect`
  decides the landing.

---

## Scope & non-goals

### In scope
- The 8 required + 1 optional checklist items (see below).
- Data-DERIVED completion (no manual dismiss persistence, no new column).
- Persistent indicator: header pill + dashboard banner.
- Soft landing via `HomeRedirect` (never hard-blocking).
- Admin-only visibility of the checklist.

### Out of scope (non-goals)
- **Hard gating / blocking** the admin from any route until onboarding is complete.
- **Manual dismiss persistence** — the unused `user.onboarding_checklist_dismissed`
  column stays unused; completion is purely derived. (A session-local "hide for now" on
  the banner is acceptable UX but is NOT persisted server-side.)
- **Welcome slides / `onboarding_completed_at`** flow — untouched.
- Extra checklist items beyond the minimum-to-bill set: employees, providers,
  community projects, first charge batch.
- **Real-time / automatic query invalidation** of the checklist as the admin completes
  items elsewhere. The checklist refetches on navigation back to `/onboarding`; live
  cross-module invalidation is deferred.
- Touching the **system-bootstrap** `OnboardingController` in `app/Http/Controllers/`
  (different concern — must NOT be modified).

### Checklist items (dependency-ordered)
1. Cluster legal/branding data → `/configuration`  *(required)*
2. Collection params: timezone / currency / payment_day / grace → `/configuration`  *(required)*
3. Towers / Sections → `/tower`  *(required)*
4. Units → `/unit`  *(required)*
5. Residents (via `unit_user`) → `/unit-user`  *(required)*
6. Board period + Treasurer → `/board-period`  *(required)*
7. Bank account / cash → `/bank-account`  *(required)*
8. Charge concepts → `/charge-concept`  *(required)*
9. Review catalogs → `/unit-type`  *(optional)*

`is_complete` = all REQUIRED (1–8) done. All destination routes already exist in `App.tsx`.

---

## Impact

### Backend — Dashboard module (`backend/Modules/Dashboard/`)
- NEW `app/Services/OnboardingChecklistService.php` — builds the items and derives `completed`.
- NEW `app/Http/Controllers/OnboardingChecklistController.php` — custom (non-CRUD) action
  returning `JsonResponse`.
- EDIT `routes/api.php` — register `GET /api/dashboard/onboarding-checklist`.
- The existing `DashboardService` stubs (`onboardingSteps()`, `summary()`) may be left as-is
  or delegated to the new service (design decision).
- No migration. No catalog/permission seeder changes anticipated beyond confirming read
  access for admin roles.

### Frontend — new onboarding module + wiring
- NEW `frontend/src/modules/onboarding/` (schema, `OnboardingResource`,
  `useOnboardingChecklist`, `OnboardingPage`, `OnboardingProgressPill`).
- NEW `HomeRedirect` (soft-landing router at `/`).
- EDIT `App.tsx` (add `/onboarding` route + `/` → `HomeRedirect`).
- EDIT `components/login-form.tsx` (navigate to `/`).
- EDIT `components/site-header.tsx` (mount the pill).
- EDIT `modules/dashboard/.../DashboardPage.tsx` (mount the banner).

### Reused (not modified beyond mounting)
- `modules/dashboard/components/OnboardingCard.tsx` — reused as the dashboard banner.
- `modules/dashboard/schema.ts` — `IOnboardingStep` shape reused by the new module.

---

## Risks & open questions

- **(MUST VERIFY at design/apply) The exact `completed` predicate for items 1 and 2.**
  Items 1 (legal/branding) and 2 (collection params) both point at `/configuration` but
  represent different "done" conditions. The real predicates must be confirmed against the
  actual `Company` model (item 1 — which legal/branding fields count as "filled": e.g.
  `legal_name`/`rfc` present?) and the actual collection-settings model
  (`tenant_collection_setting` — item 2: which of `timezone`/`currency`/`payment_day`/grace
  must be non-default to count as configured?). The other six items are unambiguous
  existence checks (`Tower`, `Unit`, `UnitUser`, an open `BoardPeriod` with a treasurer
  `BoardMember`, `BankAccount`, `ChargeConcept` each `->exists()`). **This predicate
  confirmation is the single thing to lock down before implementation.**
- Item 6 ("Board period + Treasurer") is a compound predicate: an open board period AND a
  board member holding the Treasurer role. Confirm the role/key used to identify Treasurer.
- Admin role identification: confirm the exact role slugs (`ERoleUserSlug` on the front;
  Spatie role names on the back) used to decide checklist visibility and the `HomeRedirect`
  branch.
- Performance: the endpoint runs ~8 lightweight `exists()`/`count()` queries per call;
  acceptable, but keep them as `exists()` (not full fetches) and avoid N+1.

---

## Recommended next phases

1. **`sdd-spec`** and **`sdd-design`** — can run in parallel.
   - spec: requirements + scenarios for the API contract, derived-completion rules, the
     8+1 items, indicator visibility, and soft-landing behavior.
   - design: the `OnboardingChecklistService` predicate map (the place to RESOLVE the
     items 1 & 2 question), endpoint shape, frontend module structure, and routing wiring.
2. **`sdd-tasks`** — after spec + design converge.
