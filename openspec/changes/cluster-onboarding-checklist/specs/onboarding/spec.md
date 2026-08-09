# Onboarding Checklist Specification

## Purpose

Provides cluster administrators with a dependency-ordered, data-derived progress
checklist that guides them from account creation to a fully operational billing setup.
All completion status is computed from real tenant data — no persisted "done" flag,
no migration.

---

## Requirements

### Requirement: Checklist API endpoint

The system MUST expose `GET /api/dashboard/onboarding-checklist` under the middleware
group `['throttle:api', 'auth:sanctum', 'initializeTenancy']`.

The response MUST conform to:

```
{
  items: ChecklistItem[],
  required_total: number,       // always 8
  required_completed: number,
  progress_percentage: number,  // floor(required_completed / required_total * 100)
  is_complete: boolean          // true when required_completed === 8
}
```

Each `ChecklistItem` MUST include:
`{ id, title, description, details, route, required, completed, count }`.

`completed` MUST be derived from tenant-scoped model queries (e.g. `exists()`, `count()`)
executed under the active tenant scope set by `initializeTenancy`.
`count` MUST be `0` when `completed` is `false`; otherwise the quantity that satisfies
the predicate (useful for items 3–8).

#### Scenario: Admin fetches checklist with no data registered

- GIVEN an authenticated Administrator with no tenant data registered
- WHEN `GET /api/dashboard/onboarding-checklist` is called
- THEN the response returns 9 items, `required_completed: 0`, `progress_percentage: 0`, `is_complete: false`
- AND every item has `completed: false` and `count: 0`

#### Scenario: Admin fetches checklist with all required items completed

- GIVEN an authenticated Administrator with all 8 required items satisfied
- WHEN `GET /api/dashboard/onboarding-checklist` is called
- THEN `required_completed: 8`, `progress_percentage: 100`, `is_complete: true`
- AND all 8 required items have `completed: true`

#### Scenario: Optional item does not block is_complete

- GIVEN all 8 required items are completed but no catalog (`unit_type`) records exist
- WHEN `GET /api/dashboard/onboarding-checklist` is called
- THEN `is_complete: true`
- AND item id `catalogs` has `completed: false` and `required: false`

#### Scenario: Non-admin role receives empty response

- GIVEN an authenticated Resident (or any role other than Administrator / Account Owner)
- WHEN `GET /api/dashboard/onboarding-checklist` is called
- THEN the response returns `items: []`, `required_completed: 0`, `required_total: 8`, `is_complete: true`

---

### Requirement: Checklist item definitions (8 required + 1 optional)

The system MUST return exactly the following items in dependency order.
`id` values are stable identifiers used by the frontend.

| # | id | title (ES) | route | required | completed predicate |
|---|----|----|----|----|---|
| 1 | `legal_branding` | Datos legales del clúster | `/configuration` | true | Company exists with `legal_name` AND `rfc` non-null/non-empty |
| 2 | `collection_params` | Parámetros de cobranza | `/configuration` | true | `tenant_collection_setting` exists with `payment_day` set (non-null) |
| 3 | `towers_sections` | Torres y secciones | `/tower` | true | At least one Tower exists |
| 4 | `units` | Viviendas | `/unit` | true | At least one Unit exists |
| 5 | `residents` | Residentes | `/unit-user` | true | At least one UnitUser exists |
| 6 | `board_treasurer` | Mesa directiva y tesorero | `/board-period` | true | An open BoardPeriod exists AND a BoardMember with the Treasurer role exists in that period |
| 7 | `bank_account` | Cuenta bancaria | `/bank-account` | true | At least one BankAccount exists |
| 8 | `charge_concepts` | Conceptos de cobro | `/charge-concept` | true | At least one ChargeConcept exists |
| 9 | `catalogs` | Catálogos de unidades | `/unit-type` | false | At least one UnitType exists |

The system MUST derive `completed` using `exists()` / `count()` — NEVER fetching full
records. All queries MUST run under the tenant scope established by `initializeTenancy`.

#### Scenario: Item 6 compound predicate — board open but no treasurer

- GIVEN an open BoardPeriod exists in the tenant
- AND no BoardMember with the Treasurer role exists for that period
- WHEN the checklist is fetched
- THEN item `board_treasurer` has `completed: false`

#### Scenario: Item 1 partial completion — RFC missing

- GIVEN the Company record has a `legal_name` value but `rfc` is null
- WHEN the checklist is fetched
- THEN item `legal_branding` has `completed: false`

#### Scenario: Item 2 marked complete when payment_day is set

- GIVEN `tenant_collection_setting` exists with a non-null `payment_day`
- WHEN the checklist is fetched
- THEN item `collection_params` has `completed: true`

---

### Requirement: Onboarding page

The system MUST provide a `/onboarding` route accessible only to Administrator and
Account Owner roles (gated via `PermissionRoute` or equivalent role guard).

The page MUST render:
- An overall progress indicator showing X / 8 required items completed plus a visual
  progress bar.
- Each checklist item with: title, `details` text (purpose explanation), status badge
  (Pendiente / Completado + count when > 0), and a link to `item.route`.
- The optional item (id `catalogs`) MUST be visually separated and labelled as
  recommended, not required.
- When `is_complete` is `true`, the page MUST show a success state and a link to
  `/dashboard`. The page MUST NOT trap the admin.

The page MUST fetch the checklist on mount and re-fetch on route focus (navigating
back after completing a step). No real-time cross-module invalidation is required in v1.

#### Scenario: Admin visits onboarding page mid-setup

- GIVEN an authenticated Administrator with 3 of 8 required items completed
- WHEN the admin navigates to `/onboarding`
- THEN the page shows "3 / 8" progress, items 1-3 show "Completado", items 4-8 show "Pendiente"
- AND each item displays a "Ir a registrar" link to its route

#### Scenario: Admin completes the last required item and returns to /onboarding

- GIVEN `is_complete` becomes `true` after the admin registers a charge concept
- WHEN the admin navigates back to `/onboarding`
- THEN the page refetches and displays a success state
- AND a link to `/dashboard` is visible

#### Scenario: Non-admin cannot access /onboarding

- GIVEN an authenticated Resident navigates to `/onboarding`
- WHEN the route guard evaluates the user's role
- THEN the user is redirected (e.g. to `/access-denied` or their role default route)

---

### Requirement: Persistent progress indicator

The system MUST mount an onboarding progress pill in the site header AND a banner
(using the existing `OnboardingCard` component) in `DashboardPage`, both shown only
when the authenticated user is Administrator / Account Owner AND `is_complete` is
`false`. Both MUST link to `/onboarding`.

When `is_complete` is `true` (or for any non-admin role), the pill and banner MUST
NOT render.

#### Scenario: Incomplete checklist — admin sees indicator in header and dashboard

- GIVEN an Administrator with `is_complete: false`
- WHEN the admin views any authenticated page that includes the site header
- THEN the progress pill is visible in the header linking to `/onboarding`
- AND when the admin is on `/dashboard`, the onboarding banner is also visible

#### Scenario: Complete checklist — indicators disappear

- GIVEN an Administrator whose checklist reaches `is_complete: true`
- WHEN the admin loads the site header or dashboard
- THEN neither the header pill nor the dashboard banner renders

#### Scenario: Non-admin roles never see the indicator

- GIVEN an authenticated Resident
- WHEN the resident views any page
- THEN the header pill is not rendered

---

### Requirement: Soft landing after login

The system MUST mount a `HomeRedirect` component at route `/`. After login,
`login-form.tsx` MUST navigate to `/` (not directly to `/dashboard`).

`HomeRedirect` MUST:
- Fetch or read the cached onboarding status.
- Redirect Administrator / Account Owner with `is_complete: false` → `/onboarding`.
- Redirect Administrator / Account Owner with `is_complete: true` → `/dashboard`.
- Redirect all other roles to their role-default route (unchanged from current behavior).

The admin MUST NOT be trapped: they can navigate away from `/onboarding` freely at
any time.

#### Scenario: Admin with incomplete onboarding logs in

- GIVEN an Administrator with `is_complete: false` submits the login form
- WHEN login succeeds and `login-form.tsx` navigates to `/`
- THEN `HomeRedirect` redirects the user to `/onboarding`

#### Scenario: Admin with complete onboarding logs in

- GIVEN an Administrator with `is_complete: true` submits the login form
- WHEN login succeeds
- THEN `HomeRedirect` redirects the user to `/dashboard`

#### Scenario: Resident logs in

- GIVEN an authenticated Resident submits the login form
- WHEN login succeeds
- THEN `HomeRedirect` redirects the user to their role-default route (not `/onboarding`)

#### Scenario: Admin navigates away from /onboarding

- GIVEN an Administrator is on `/onboarding` with an incomplete checklist
- WHEN the admin clicks a link in the sidebar or browser back button
- THEN the admin reaches the target route without being redirected back to `/onboarding`

---

## Non-Goals (explicit)

The following are explicitly OUT of scope for this change and MUST NOT be implemented:

- **Hard gating**: no route is blocked or inaccessible until onboarding completes.
- **Persisted manual dismiss**: `user.onboarding_checklist_dismissed` stays unused;
  no new column. A session-local hide on the banner is acceptable UX but is NOT
  persisted server-side.
- **Welcome-slides / `onboarding_completed_at`**: that flow is untouched.
- **Additional checklist items**: employees, providers, community projects, first
  charge batch are NOT in the 8+1 set.
- **Real-time query invalidation**: the checklist does NOT automatically refresh
  when the admin completes a step in another module. Re-fetch on navigation back to
  `/onboarding` is sufficient for v1.
- **System-bootstrap OnboardingController** at `app/Http/Controllers/`: MUST NOT be
  modified.
