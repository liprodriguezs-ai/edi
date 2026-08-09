---
name: tracking-analytics-consent-gap
description: New shared/analytics/ tracking module (FB Pixel + GA4 + /track CAPI relay) sends the server-side POST unconditionally, bypassing the consent gate that only wraps the client-side fbq/gtag calls
metadata:
  type: project
---

New module `frontend/src/shared/analytics/{eventNames,consent,clickIds,loadSnippets,trackEvent}.ts`
wires Facebook Pixel + GA4 + backend `/track` (Conversions API relay) across register
(`shared/auth/useSession.ts` → CompleteRegistration + StartTrial) and checkout
(`modules/checkoutSubscription/hooks/useBillingQuoter.ts` → InitiateCheckout,
`SelectPlanPage.tsx` `SuccessView` → Purchase). Reviewed 2026-07-01 (first pass, uncommitted).

**Headline finding (Critical):** `trackEvent()` in `shared/analytics/trackEvent.ts` only wraps
`window.fbq?.(...)`/`window.gtag?.(...)` inside `if (hasAnalyticsConsent())` — the
`apiClient.post('/track', ...)` call right below is UNCONDITIONAL, sent regardless of consent.
This diverges from the sibling, same-contract `landing/src/lib/trackEvent.ts`, which does
`if (!hasConsent()) return;` as the very FIRST line, gating everything including the backend
POST. Since the app has no in-app consent banner yet (by design, per `consent.ts`'s own
docblock) and localStorage doesn't cross the landing→app origin boundary, `hasAnalyticsConsent()`
evaluates to `false` in the app today, ALWAYS — so client pixels never actually fire in prod,
but the server-side `/track` POST (carrying raw email, external_id, `fbp`/`fbc`/`gclid`) fires
on every register/checkout with zero gating. Contradicts the project's own stated legal caution
("pendiente de validación legal antes de asumir consentimiento implícito").

**Other findings from that pass:**
- `SuccessView` (`SelectPlanPage.tsx`) dedupes Purchase firing via a `purchaseTrackedRef` (correctly
  StrictMode-safe), but `consumePurchaseIntent()` deletes the persisted event_id on first read. A
  page refresh/revisit of `?success=true` AFTER the intent was already consumed remounts the
  component (ref resets), generating a brand-new random `event_id` → a duplicate, non-deduped
  Purchase event alongside the original.
- `getFbclid()` (`clickIds.ts`) is captured to localStorage but never consumed anywhere —
  `trackEvent.ts`'s `fbc` field only reads the `_fbc` cookie. The docblock promises it'll be
  usable in `/track` and Stripe checkout metadata; neither wires it in. Dead code vs. documented
  intent.
- No test coverage for the new module (dedup logic, consent gate, event_id threading), despite
  the sibling `billingBands.test.ts` existing in the same module for the same PR.
- Everything else verified clean: no `any`; `pnpm eslint` and `pnpm exec tsc -b --force` both pass
  with zero output; `apiClient` reused (no new axios instance); event_id correctly threaded
  InitiateCheckout→Purchase via `persistPurchaseIntent`/`consumePurchaseIntent`, matching backend's
  `BillingCheckoutRequest`/`TrackEventRequest` contracts (`ga_client_id` rename verified — no stale
  `client_id` references, no unused `getGclid` import left in `useBillingQuoter.ts`); raw PII sent
  unhashed over HTTPS (correct per spec — server hashes).
