---
name: tracking-event-seam
description: Facebook CAPI + GA4 tracking module (Modules/Tracking) cross-stack contract — POST /api/track, checkout Purchase dedup, and a confirmed double-count bug in registration
metadata:
  type: project
---

Reviewed 2026-07-01. Scope: `backend/Modules/Tracking/**`, `frontend/src/shared/analytics/**`, `landing/src/lib/trackEvent.ts`, checkout event_id/ga_client_id thread.

## ALIGNED
- `POST /api/track` (`TrackController::track` + `TrackEventRequest`) vs `frontend/src/shared/analytics/trackEvent.ts` + `landing/src/lib/trackEvent.ts`: field names, nullability, `user_data` sub-fields all match. Response `{status,event_id}` 202 is correctly ignored by both clients (fire-and-forget).
- Event-name map (`Lead/StartTrial/CompleteRegistration/InitiateCheckout/Purchase` → GA4 names) identical in 3 places: `Modules/Tracking/app/DTO/TrackingEvent.php` + `GoogleAnalyticsChannel::GA4_EVENT_NAME_MAP`, `frontend/src/shared/analytics/eventNames.ts`, `landing/src/lib/trackEvent.ts`.
- Checkout → Purchase dedup chain (`IBillingCheckoutRequest.event_id`/`ga_client_id` → `BillingCheckoutRequest` → `MultiItemSubscriptionService::buildCheckout` Stripe metadata → `StripeWebhookListener::dispatchPurchaseTracking`): field names match end-to-end, `BillingResource.checkout()` passes payload through unchanged. Browser Purchase (SelectPlanPage `SuccessView`, via `consumePurchaseIntent()`) and webhook Purchase share the same `event_id` — correctly deduped.
- `/api/track` never dispatches to `google_analytics` (Facebook only) — client's own gtag call covers GA4 for browser-originated events, so no double-count there.

## CRITICAL — confirmed double-count in registration (GA4 always, Facebook usually)
`AuthController::dispatchRegistrationTracking()` (backend/app/Http/Controllers/AuthController.php:341) dispatches BOTH `CompleteRegistration` and `StartTrial` to `['facebook','google_analytics']` on every `/auth/register`, on the stated assumption "StartTrial has no browser counterpart here." That assumption is **stale**: `frontend/src/shared/auth/useSession.ts:32-36` fires `trackEvent('CompleteRegistration', ...)` AND `trackEvent('StartTrial')` client-side right after `registerRequest()` resolves — each of those already hits gtag (browser) + POST `/track` (Facebook via `TrackController`, dispatcher `['facebook']` only).
- **GA4**: server MP hit (register) + browser gtag hit (useSession) = 2 counts per registration, for both `sign_up` and `start_trial`. MP never dedupes against gtag (this exact rule is documented in `TrackingDispatcher`'s own class docblock, contradicted by this call site).
- **Facebook**: also double-counted because `RegisterRequest` (frontend/src/shared/auth/authService.ts:12) has NO `event_id` field — `/auth/register` never sends one, so `dispatchRegistrationTracking` always falls into `TrackingEvent::fromArray`'s random-UUID fallback, which differs from the random id `useSession.ts`'s own `trackEvent()` generates for its client+CAPI pair. Two different event_ids reaching Facebook = no dedup.
- Fix shape: thread a client-generated `event_id` through `RegisterRequest`/`authService.ts`/`signup-form.tsx` into `/auth/register` (mirrors the `InitiateCheckout`→checkout `event_id` pattern already implemented), reuse it in `useSession.ts`'s `trackEvent('CompleteRegistration', ...)` call, and drop `google_analytics` (and the independent `StartTrial` dispatch) from `dispatchRegistrationTracking` entirely — the browser already covers both events for this flow.
