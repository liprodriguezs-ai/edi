---
name: checkout-subscription-module
description: Patterns and issues found in the Stripe checkout subscription flow (SelectPlanPage, useSelectPlan, CheckoutSubscriptionResource)
metadata:
  type: project
---

## Module structure
Files reviewed 2026-06-21:
- `src/modules/checkoutSubscription/services/CheckoutSubscriptionResource.ts`
- `src/modules/checkoutSubscription/hooks/useSelectPlan.ts`
- `src/modules/checkoutSubscription/SelectPlanPage.tsx`

## Known issues (from review)

### Duplicate methods in CheckoutSubscriptionResource
`assignPlan` (line 43) and `changePlan` (line 92) are IDENTICAL — both POST to `/config/subscription/change-plan` with `{ subscription_plan_id: planId }`. One is dead code. `assignPlan` appears unused; `changePlan` is the one consumed by `useSubscription`.

### IStripeSubscription missing current_period_start
`IStripeSubscription` (exported interface, line 14) does NOT include `current_period_start`. The `getMySubscription` return type uses an intersection `IStripeSubscription & { current_period_start: string | null }` to add it. This is correct structurally but the field is always hardcoded to `null` because the backend doesn't send it. `useSubscription.ts` and `SubscriptionPage.tsx` both define their own local `SubscriptionDetails`/`SubscriptionInfo` interfaces with `current_period_start: string | null` — tight coupling via structural typing but no single source of truth. If backend ever adds the field, the mapping in `getMySubscription` needs updating.

### Polling restart bug in SuccessView (Reintentar)
"Reintentar" button sets `attemptsRef.current = 0` and `setTimedOut(false)`, but does NOT restart the polling `setInterval` — that interval was already cleared when `timedOut` was set. Resetting state causes a re-render that goes to the "confirming" view, but there is no active interval polling anymore. Fix: move the polling effect to depend on `timedOut` so it restarts when `timedOut` goes back to `false`, or extract polling into a separate component/hook that remounts.

### getPortalUrl redirect — no truthy guard
`handleGoToPortal` in `StatusAlert` (line 80-81) does `window.location.href = url` without checking if `url` is truthy. If backend returns `{ url: '' }` or `{ url: null }`, this navigates to the current origin root. By contrast, `createCheckout` correctly guards with `if (url)`.

### handleCheckout error — no toast, silent error
`useSelectPlan.handleCheckout` catch block (line 62-65) only sets `setError(state)` — no `toast.error()`. The error is displayed via inline `{error && <div>}` in `OwnerPlanView`. This is technically acceptable but inconsistent with how errors surface elsewhere in the app. Minor.

### async event handler without void in SelectPlanPage
`handleGoToPortal` is async but assigned directly to `onClick`. Works fine in React 19 but breaks ESLint's `@typescript-eslint/no-misused-promises` if enabled. Pattern is consistent with rest of codebase so not a project violation.

## Slice 3 — billing_package builder (reviewed 2026-06-30)

New files: `schema.ts` (Zod API DTOs IBillingCatalog/IBillingQuote, NOT CRUD entities — correct use of Zod for response validation), `billingBands.ts` (pure helpers deriveBands/bandIndexFor/activeBand/resolveMaxUnits/ensureBasePackage + 11 passing unit tests), `services/BillingResource.ts` (hand-rolled object on apiClient — NOT createCrudApi, correct because /billing/catalog|quote|checkout are custom actions, not REST CRUD; mirrors existing CheckoutSubscriptionResource), `hooks/useBillingQuoter.ts` (catalog load + debounced live quote + checkout), `components/PlanBuilder.tsx` (slider + interval toggle + package checkboxes + quote preview).

Conventions HONORED: no `any`; Zod-validates catalog/quote responses; apiClient (no raw axios); toast sileo `toast.error(title, desc)`; boneyard `Skeleton name= loading`; formatPrice uses Intl es-MX (minor units /100); no `new Date()`. ESLint clean, tests green.

### Slider a11y gap (Radix Slider — RECURRING, applies to any slider)
PlanBuilder puts `id`, `aria-label`, `aria-valuetext` on `<Slider>` (shadcn wrapper) and `<label htmlFor="plan-units-slider">` on the visible label. BUT shadcn `components/ui/slider.tsx` spreads `{...props}` onto `SliderPrimitive.Root` (a `<span>`), NOT onto `SliderPrimitive.Thumb` — the Thumb is the element with `role="slider"`. Radix does NOT forward Root aria to the Thumb. Net: the focusable thumb has NO accessible name and NO aria-valuetext (only raw aria-valuenow). `<label htmlFor>` on a span does nothing for AT. Real fix needs the Slider atom to forward labelling props to the Thumb (tension with "NUNCA modificar components/ui/"). The `aria-live="polite"` units region partially mitigates by announcing value+plan on change.

### Quote error has no retry affordance
`useBillingQuoter` sets `quoteError` boolean; PlanBuilder shows a message and disables "Contratar", but the only recovery is changing slider/interval/packages (which re-fires the debounced quote). No explicit retry button.

### clearCheckoutError is dead code
Defined + returned from `useBillingQuoter` but never consumed (checkout() already resets error on entry). `activeBand()` export is test-only (PlanBuilder computes its own inline). 

### Debounced quote pattern is solid (done well)
250ms setTimeout + `quoteTokenRef` monotonic token + `active` flag + clearTimeout cleanup → no stale-response races, no quote-on-drag (only after drag settles). Catalog loads once with `cancelled` guard. `declare module 'axios'` adds typed `skipPermissionsModal` config flag (clean, no casting) so 403 business-rule errors (not_account_owner) bypass the global PermissionsModal and are handled inline by code in handleCheckoutError.

### Minor nits
- `setInterval`/`interval` local names in useBillingQuoter shadow the global timer names (no actual timer use in the hook; readability smell, ESLint does not flag).
- `text-[var(--green,theme(colors.primary.DEFAULT))]` — `--green` IS defined in index.css, so the `theme()` fallback is unnecessary and fragile in Tailwind v4.
- Three `aria-live="polite"` regions (units, total, contact-sales block) can compete/be chatty during slider drag.
- All packages (incl. addons) selected by default → default quote is the most expensive config (product decision, not a bug).

## What is done well
- `cancelled` flag in `useSelectPlan.useEffect` prevents state updates after unmount — correct pattern.
- Polling `clearInterval` in both success and timeout branches + cleanup return — memory-safe.
- Toast imports correct (sileo, not shadcn).
- apiClient used throughout, no raw axios.
- No `any` types.
- `isCheckingOut` per-plan loading state (tracks plan.id not just boolean) — good granularity.
- `disabled={isAnyLoading}` on all plan buttons during checkout — correct.
