---
name: subscription-seam
description: Subscription self-service seam review (2026-06-21): Stripe checkout, portal, cancel, change-plan, getSubscription — envelope shapes, error handling, nullable fields, auth.
metadata:
  type: project
---

# Subscription self-service seam — findings (2026-06-21)

## Endpoint map (all confirmed aligned)

| FE call | Backend route | Controller method |
|---|---|---|
| GET /config/subscription | GET config/subscription | getSubscription() |
| POST /config/subscription/checkout | POST config/subscription/checkout | checkoutSubscription() |
| GET /config/subscription/portal | GET config/subscription/portal | subscriptionPortal() |
| POST /config/subscription/cancel | POST config/subscription/cancel | cancelSubscription() |
| POST /config/subscription/change-plan | POST config/subscription/change-plan | changeSubscriptionPlan() |

## CRITICAL: checkout envelope drift

Backend `checkoutSubscription()` returns `{ url }` (flat).
Frontend `CheckoutSubscriptionResource.createCheckout()` types the Axios response as
`{ url: string }` and reads `data.url` → because Axios wraps in `.data`, this correctly reads
`response.data.url`. ALIGNED.

## CRITICAL: portal envelope drift

Backend `subscriptionPortal()` returns `{ url }` (flat).
Frontend `getPortalUrl()` types as `{ url: string }` and reads `data.url`. ALIGNED.

## CRITICAL: cancel response — frontend ignores the `data` field

Backend `cancelSubscription()` returns `{ message, data: <IStripeSubscription|null> }`.
Frontend `cancelSubscription()` in Resource: `await apiClient.post(...)` — void return, data discarded.
`useSubscription.handleCancel()` then calls `loadDetails()` which does a fresh `GET /config/subscription`.
This is a round-trip refresh instead of a local update, but it works correctly (no data loss).
ALIGNED (with minor perf inefficiency).

## CRITICAL: change-plan response — frontend ignores the `data` field

Backend `changeSubscriptionPlan()` returns `{ message, data: { status, subscription_plan_id, plan } }`.
Frontend `changePlan()` / `assignPlan()`: void return, data discarded.
Same pattern: triggers `loadDetails()` for a fresh fetch. ALIGNED.

## CRITICAL: GET /config/subscription — envelope shape

Backend returns `{ data: { status, current_plan, unit_count, unit_limit, available_plans, subscription } }`.
Frontend reads `data.data` (via `apiClient.get<{ data: ConfigSubscription }>(...)`).
`ConfigSubscription` interface: `{ status, current_plan, unit_count, unit_limit, available_plans, subscription }`.
ALIGNED — no drift.

## WARNING: `current_period_start` is mapped to null explicitly

Backend `presentCashierSubscription()` NEVER returns `current_period_start`.
Frontend `getMySubscription()` explicitly maps `current_period_start: null` as a comment note.
`SubscriptionPage.tsx` renders the "Inicio del periodo" card conditionally on `subscription.current_period_start`.
This tile will ALWAYS be hidden because the backend never sends this field.
The field is hardcoded null in the Resource — this is intentional (comment on L76), not a bug.
ACCEPTED PATTERN — documented in the code.

## WARNING: 403 on checkout not shown distinctly

Backend `checkoutSubscription()` returns 403 when the logged-in user is not the account owner.
Frontend `useSelectPlan.handleCheckout()` catches all errors with a generic
`setError('No se pudo procesar la selección del plan.')`.
The apiClient `handle403()` DOES open the PermissionsModal globally, so the user will see a modal.
No silent swallow — 403 is surfaced via the global modal. MINOR UX gap only (two error surfaces).

## WARNING: 409 on portal not shown distinctly

Backend `subscriptionPortal()` returns 409 when owner not found or Stripe customer creation fails.
Frontend `handlePortal()` catches with generic `toast.error('Error', 'No se pudo abrir el portal...')`.
409 is a business error here (not a conflict), so a generic toast is adequate but could be more specific.
Acceptable pattern for this project.

## WARNING: 404 on cancel not shown distinctly

Backend `cancelSubscription()` returns 404 when no subscription exists.
Frontend `handleCancel()` catches with generic toast. 404 is treated same as 500.
SubscriptionPage guards the cancel button to only appear when subscription exists, so this is unlikely to fire in practice.

## Confirmed alignments

- Request body `subscription_plan_id` (string UUID): both sides use exact same field name.
- Request body `at_period_end` (boolean): both sides use exact same field name.
- `IStripeSubscription` fields: `stripe_status, trial_ends_at, ends_at, cancel_at_period_end, current_period_end` — all 5 are returned by `presentCashierSubscription()` and read by the FE. ALIGNED.
- ISO 8601 dates: backend uses `->toIso8601String()`, `displayFormatDate` uses `parseISO` from date-fns. ALIGNED.
- Auth headers: all 5 subscription endpoints are under `['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']` middleware. `apiClient` injects `Authorization: Bearer <token>` from sessionStore. ALIGNED.
- Permission ability: backend uses `manage.subscription` (custom, not the Orion `read/create/update/delete` pattern). FE uses `useRoles(ACCOUNT_OWNER)` as the gate — NOT checking `manage.subscription`. This means an Administrator who has `manage.subscription` seeded would be blocked by the FE role guard but pass the backend. Minor FE over-restriction (acceptable since admin can access via other paths).
- `available_plans` returned by getSubscription is typed as `ISubscriptionPlan[]` on FE and the backend returns `SubscriptionPlan::where('is_public', true)->orderBy('sort_order')->get()` — shape matches `ISubscriptionPlan` interface. ALIGNED.
- `is_stripe` field: backend `SubscriptionPlan` model has `is_stripe` column; FE `ISubscriptionPlan.is_stripe: boolean`. ALIGNED.
- Money: `price` is treated as minor units in the Zod schema (`z.coerce.number().int().min(0)`) and `formatPrice` divides by 100. Backend seeder stores in minor units. ALIGNED.
- `getSubscription` gated by `manage.subscription` on backend; FE gated by `useRoles(ACCOUNT_OWNER)`. Different gates — backend gate is Sanctum token ability, FE gate is role check. This is a deliberate over-restriction on FE side (only owner can see the page, but technically admin with manage.subscription could call the API directly). Not a contract bug.

## Pattern note

The subscription module establishes a pattern of "discard-and-refetch" for mutations: the FE ignores the `data` field returned by cancel/change-plan and does a fresh GET. This is consistent and correct but slightly wasteful (2 API calls on each mutation). No contract bug.
