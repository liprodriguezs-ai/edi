# Design: Stripe-Native Subscription Controls

## Technical Approach

Split `SubscriptionStateService` into a cheap **gate** (hot path, `tenant_entitlement` only) and a rich **narrative** (allowlisted routes, gate + one Cashier read). Today's two entry points are PRESERVED, not collapsed: an actor-aware path with the platform-role short-circuit, and a tenant-scoped path without it. The `stripe_trialing` mirror dies: Stripe trials via Cashier `onTrial()`, courtesy trials via `source=trial`. Maps to `specs/subscription-state-derivation` and `specs/subscription-status-allowlist`.

## Architecture Decisions

### Decision: Two entry points preserved; one private gate core

**Choice**: `SubscriptionStateService` (DI unchanged) exposes:
- `gateFor(?User, ?Tenant): SubscriptionGate` — **actor-aware**: `hasRole([SUPER_ADMIN, LAWYER]) → exempt` short-circuit, else delegates to the private core. Sole consumer: `EnsureSubscriptionActive.php:112` → `gateFor(...)->isBlocked`.
- `narrativeFor(User, ?Tenant): array` — **tenant-scoped, NO role short-circuit** (successor of today's `forTenant()`; same output keys). Consumers: `AuthController.php:308` and `TenantProvisioningController.php:65` — the EXACT and ONLY narrative consumers (`/config/subscription` never references this service; `getSubscription` builds from its own presenters).
- private `tenantGate(User, ?Tenant): SubscriptionGate` — the single `is_blocked` computation both paths share.

`forUser()`/`forTenant()` deleted.
**Rationale**: `TenantProvisioningController` passes the super-admin actor + the freshly created tenant precisely to DESCRIBE the new cluster; an actor-aware narrative would return `exempt` for it in production while `AdminTenantProvisionTest` stayed green (its `superAdminToken()` at `:383-394` authorizes by token ability, not `SLUG_SUPER_ADMIN`, so `hasRole()` is false in tests). Tenant-scoped narrative = strict rename for both callers, zero behavior change.

### Decision: Gate vocabulary excludes trial/active

Gate `status ∈ {none, exempt, blocked, entitled}`; narrative refines `entitled → active|trial`, `blocked → expired|none` (five public states preserved; `exempt` lives on the actor-aware path, as today). The expired-vs-none and stripe-history `exists` queries move to the narrative.
**Query-count claim (precise)**: hot path is **never MORE than today; EQUAL on the entitled branch** — the dominant path (every paying customer) runs 1 entitlement query before and after. Strictly fewer only when blocked: 2→1, or 3→1 with `PENDIENTE_PAGO` + prior entitlements. The `DB::listen` assertion must be "≤ pre-change AND zero `subscriptions`-table queries", not "equal".

### Decision: Composition invariant = convention + named enforcement test

`final readonly SubscriptionGate` prevents mutating the gate, but `narrativeFor` returns an array whose `'is_blocked' => $gate->isBlocked` entry a future edit could replace — so this is NOT structurally impossible, and the design does not claim it. Enforcement: (1) `is_blocked` is assigned exactly once, from the DTO; (2) entitlement query helpers stay private to the gate core; (3) **`SubscriptionStateCompositionTest`** — a state-matrix test asserting `narrativeFor(...)['is_blocked'] === tenantGate(...)->isBlocked` across all five states — is the named enforcement mechanism.
**Alternative rejected**: a delegating narrative DTO (holds the gate, exposes `is_blocked` only by delegation). Both narrative consumers embed the array into JSON payloads; auditing every array-access touchpoint adds risk to a paywall refactor for a guarantee that still ends in one serialization assignment.

### Decision: `App\Services\AccountOwnerResolver`, container-`scoped` — extracts TWO sites, excludes one

**Choice**: extract the two IDENTICAL duplicates (`ConfigurationController.php:865-870`, `BillingCheckoutController.php:191-196`: `users()->role(SLUG_ACCOUNT_OWNER)`, count===1 guard) plus the narrative as third consumer. **`SubscriptionPaymentController.php:194-199` is EXCLUDED**: it uses `$tenant->owners()` with deliberately different failure behavior (returns empty instead of throwing when the role is unseeded), pinned by `SubscriptionPaymentFlowTest.php:692` — naive consolidation breaks that pin. It keeps its private variant, documented with a pointer to the resolver.
Memo: per-request instance array keyed by tenant id, registered `$this->app->scoped(...)` in `AppServiceProvider` (currently has no bindings). Precedent: `Modules/Collections/app/Providers/CollectionsServiceProvider.php:56` — `scoped(DelinquencyRestrictionService::class)`, with the scoped-vs-singleton comment at `:52`.
**Alternatives rejected**: trait (no memo point); singleton/static cache (Octane: state leaks across requests).
**Payoff**: change-plan requests resolving the owner twice (`:1202`, `:1484`) pay one query; `getSubscription` does a SINGLE lookup and is unaffected. Narrative cost: +2 queries (owner + subscriptions row), allowlisted routes only.

### Decision: Allow-list home = `Modules/SubscriptionPlan/app/Services/StripeSubscriptionStatuses.php` — three NAMED sets, two questions

**Choice**: `final class` on SDK constants, no invented strings:

```php
public const LIVE = [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING];      // "already paying / manageable?"
public const DUNNING = [Subscription::STATUS_PAST_DUE, Subscription::STATUS_UNPAID];   // "charge failing right now?"
public const PAYMENT_SIGNAL = [...self::LIVE, ...self::DUNNING];                        // "does Stripe still speak?"

public static function providesPaymentSignal(?string $stripeStatus): bool; // null-safe ∈ PAYMENT_SIGNAL
```

`LIVE` consumers: `StripeWebhookListener.php:263`, `ConfigurationController.php:1216/:1486`, `BillingCheckoutController` (private const deleted). `DUNNING`/`PAYMENT_SIGNAL` consumer: narrative only.
**Rationale**: SubscriptionPlan owns billing; module convention puts companion value classes next to services (`UnitLimitCheck.php`), no `Support/` exists; `app/Listeners` already imports this module. `LIVE` ("may manage / already paying") and `PAYMENT_SIGNAL` ("Stripe still authoritative for payment state") answer DIFFERENT questions — deliberately separate names so neither is reused for the other by accident; the union is derived (`...spread`), never re-listed.

### Decision: `payment_issue` — per-branch predicate; entitled branch is a strict no-op vs today

**Choice**:

```php
// ENTITLED branch — today's unconditional tenant-status check KEPT (zero customer-visible
// change on a paywall refactor). DUNNING primary is an ADDITIONAL true-condition covering
// rows Stripe marks past_due/unpaid while tenant.status lags.
$paymentIssue = $tenant->status === Tenant::STATUS_PENDIENTE_PAGO
    || ($sub !== null && in_array($sub->stripe_status, StripeSubscriptionStatuses::DUNNING, true));

// BLOCKED branch — the PAYMENT_SIGNAL predicate is what makes the canceled+PENDIENTE_PAGO pin work.
$paymentIssue = ($sub !== null && in_array($sub->stripe_status, StripeSubscriptionStatuses::DUNNING, true))
    || (! StripeSubscriptionStatuses::providesPaymentSignal($sub?->stripe_status)
        && $tenant->status === Tenant::STATUS_PENDIENTE_PAGO
        && $this->hasStripeHistory($tenant));   // D8 guard
```

"No live payment signal" ≡ `stripe_status ∉ LIVE ∪ DUNNING` — one rule covering `canceled`, `incomplete`, `incomplete_expired`, `paused`, AND the absent row.
**Rationale for keeping the unconditional entitled check**: `handleInvoicePaymentFailed` (`StripeWebhookListener.php:385`) sets `PENDIENTE_PAGO` unconditionally and writes NO subscription status; Stripe emits `invoice.payment_failed` before `customer.subscription.updated` and retry dunning may leave `status='active'` for a window whose width is Stripe-dashboard-configured — explicitly out of scope. Dropping the check would open an unmeasured gap in a payment warning; the stale-banner risk on the other side is already handled by `handleInvoicePaymentSucceeded` resetting `ACTIVO` (`:355`). All three spec scenarios hold: `past_due` → primary; canceled+`PENDIENTE_PAGO` → blocked fallback (recovery-screen pin, obs #2281); no-row + stripe history + `PENDIENTE_PAGO` → blocked fallback.
**Alternatives rejected**: "fallback only when row absent" (a canceled row regresses the recovery screen); PAYMENT_SIGNAL-gated entitled branch (opens the dunning-window gap above).

### Decision: `presentCashierSubscription` stays independent

It reports billing-screen detail (period ends, `onGracePeriod() && canceled()`), not access state. It adopts `AccountOwnerResolver` and the shared constants; nothing else changes.

### Decision: Cashier timestamp normalization = one shared Eloquent cast, `App\Casts\AppTimezoneCast`

**Problem (measured)**: Cashier's `createFromTimestamp($ts)` returns UTC Carbons (Carbon 3.11.4); Laravel's `datetime` cast serializes the Carbon's OWN wall time without tz conversion, so `subscriptions.trial_ends_at`/`ends_at` (WebhookController `:162`/`:175`, `Subscription.php:1093`) land 6h ahead in the `timestamp without time zone` columns; `user.trial_ends_at` (`:75`) has NO cast at all and serializes via Carbon `__toString` — same skew. Exactly these THREE columns; `currentPeriodEnd()` is correct and untouched.

**Choice**: `App\Casts\AppTimezoneCast` (`CastsAttributes`): `set()` = `Carbon::parse($value)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')`; `get()` = `Carbon::parse($value, config('app.timezone'))`. Applied in `App\Models\Subscription::casts()` (`array_merge(parent::casts(), [...])` for `trial_ends_at`, `ends_at`) and added to `App\Models\User::casts()` for `trial_ends_at`. The already-registered model overrides (`AppServiceProvider:35-36`) are the extension point — vendor untouched.
**Why a cast**: casts intercept `setAttribute`, so EVERY write path including Cashier's vendor assignments is covered. **Idempotency by construction**: `setTimezone()` converts the instant (no wall-time shift), so an already-local Carbon is a no-op, and a tz-naive string parses in app tz (PHP default) — no value heuristic, no tz detection.
**Alternatives rejected**: `booted()` saving hook (by save time the string is already serialized — tz info LOST, the exact trap); three `Attribute` mutators (duplication, no reuse). **Home**: `app/Casts/` — the `artisan make:cast` default; these models live in `app/Models`, so `Modules/SubscriptionPlan/app/Services/` would be the wrong layer for a cast.

**One-shot correction (dev-only data, "no hay nada en prod")**: a data migration `normalize_stripe_timestamp_timezones` — `UPDATE ... SET col = (col AT TIME ZONE 'UTC') AT TIME ZONE '{config app.timezone}'` for the three columns (non-null rows). Migration, NOT a command: the migrations ledger runs it exactly once — running `migrate` twice cannot double-shift, whereas a re-runnable command would need an unreliable value heuristic. `down()` = inverse conversion.
**Narrative interaction**: none in code — `entitledNarrative()` reads `$subscription->trial_ends_at->toIso8601String()`; the cast's `get()` now yields an app-tz Carbon whose ISO carries `-06:00`, so the serialized instant simply becomes CORRECT.

### Decision: `trial_days_left` is computed by the API; frontend `daysUntil` dies

**Problem**: two implementations of "days left" — FE `daysUntil()` (ms-rounding over Stripe `trial_ends_at`) vs `presentTrials()['days_left']` (`ConfigurationController.php:623`, calendar days over entitlement expiry) — different algorithm AND different source, demonstrably disagreeing. User rule: the API owns this number.

**Choice**: the ONE canonical rule is **calendar days** (the existing backend rule, named by the spec): `max(0, now()->startOfDay()->diffInDays($end->copy()->startOfDay()))`, as shared helper **`App\Support\CalendarDays::until(?CarbonInterface $end): ?int`** (null in → null out). Home: `app/Support/` (precedent: `SpreadsheetSafe`, `MediaAttachmentPolicy`); consumers span `app/Services` and `Modules/Configuration`, so no module owns it, and it is not status vocabulary (NOT `StripeSubscriptionStatuses`).
- **Narrative**: both trial branches of `entitledNarrative()` add `'trial_days_left' => CalendarDays::until($end)` computed from the SAME expiry instant that produces `trial_ends_at` (Stripe branch: `$subscription->trial_ends_at`; courtesy branch: the furthest entitlement expiry Carbon, BEFORE ISO serialization). Null-safe: null when the expiry is null; key present only on `trial` status (same shape as `trial_ends_at`/`trial_auto_charges`). `trial_auto_charges`, `expires_at`, and all other narrative fields are unaffected.
- **`presentTrials()`** replaces its inline `diffInDays` literal with the helper — `days_left` semantics preserved (plus the explicit zero clamp), no second literal implementation remains.
- **Hard-won behaviors survive**: day one of a 30-day trial → end date is +30 calendar days → 30 (not 31); expiry day → 0; `max(0, ...)` keeps the clamp (Carbon 3 `diffInDays` is SIGNED — obs: the signed-diff bug class). Addition 1 is what makes calendar-day counting correct: the stored local date is no longer skewed.
- **Frontend removal**: delete `daysUntil` + its import from `dateHelper.ts`; `Session.ts` narrative type gains `trial_days_left?: number | null`; `SelectPlanPage.tsx` drops the `useMemo` at `:459-462` and reads `subscription?.trial_days_left ?? null` (the existing `trialDaysLeft` prop plumbing to `:631` is unchanged — only the source changes); `UpcomingChargeBanner.tsx:29` reads `trial_days_left` directly (its React-Compiler comment about `daysUntil` goes with it). `__tests__/dateHelper.test.ts` is UPDATED, not deleted: the four `daysUntil` cases move to backend feature assertions (below) and the file repoints to the remaining exported helpers (`formatLocalDateString`/`parseLocalDateString`/`formatTimeHHmm`). `SelectPlanPage.test.tsx` fixtures feeding `trial_ends_at` also feed `trial_days_left` where copy asserts counts.

### Decision: fix the pre-existing `rules-of-hooks` lint ERROR in `SelectPlanPage.tsx` here

`:510-511` declares two `useState` after the early `return <SuccessView />` at `:481` — inherited from uncommitted `signup-requires-plan-and-card` WIP, but our regression gate requires `pnpm lint` clean and this change edits the SAME hook region. **Fix in scope, mechanical**: hoist the pure derivations (`isTrialBrowsing`, `blockedReason`, `planPickerIsTheTask`) plus the two `useState` above the `if (isSuccess)` return. Behavior-preserving: on the success view the states are simply unused. Declaring it out of scope was rejected — it would force excluding a file we modify from our own lint gate.

## Data Flow

    middleware ──→ gateFor (role short-circuit) ──→ tenantGate ──→ tenant_entitlement (1q) ──→ isBlocked
    auth/me · provisioning ──→ narrativeFor ──→ tenantGate ⊕ AccountOwnerResolver ──→ owner->subscription('default')

Narrative on `entitled`: Cashier `onTrial()` → `trial` (`trial_ends_at` = subscription's, `trial_auto_charges=true`); else all-`source=trial` → courtesy `trial` (entitlement expiry, `false`); else `active`. No Cashier row + stripe-source entitlements → `active` (owner-unresolved degrades safely). Both trial branches also emit `trial_days_left` = `CalendarDays::until(same expiry instant)`.

    Cashier vendor write (UTC Carbon) ──→ AppTimezoneCast::set (setTimezone → local) ──→ timestamp column
    narrative / presentTrials ──→ CalendarDays::until ──→ trial_days_left / days_left (ONE rule)

Webhook: delete marker write `:493`, the `$isTrialing` param, AND **`isTrialingSubscription()` (`:507-510`) — dead code once the param goes**: its only call sites (`:189`, `:585`) are that argument, and `subscriptionFallbackExpiry()` (`:525-547`) does its own inline `!== 'trialing'` check. Drop `'metadata' => null` at `:343` keeping the monotonic expiry update; `:263` → `LIVE`.

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `backend/app/Services/SubscriptionStateService.php` | Modify | gateFor/narrativeFor/tenantGate split; delete `isTrialLike()`, `forUser()`, `forTenant()`; both trial branches emit `trial_days_left` via `CalendarDays::until` |
| `backend/app/Casts/AppTimezoneCast.php` | Create | shared cast: `set()` converts any DateTime to app tz (idempotent via `setTimezone`), `get()` parses in app tz |
| `backend/app/Models/Subscription.php` | Modify | `casts()` override merging parent: `trial_ends_at`/`ends_at` → `AppTimezoneCast` |
| `backend/app/Models/User.php` | Modify | add `'trial_ends_at' => AppTimezoneCast::class` to `casts()` (today UNCAST — serialized via UTC `__toString`) |
| `backend/database/migrations/*_normalize_stripe_timestamp_timezones.php` | Create | one-shot dev-data correction: `(col AT TIME ZONE 'UTC') AT TIME ZONE app-tz` on the 3 columns; ledger = idempotency |
| `backend/app/Support/CalendarDays.php` | Create | `until(?CarbonInterface): ?int` — the ONE calendar-days rule, `max(0, startOfDay diffInDays)` |
| `backend/app/Services/SubscriptionGate.php` | Create | readonly DTO (isBlocked, status, isOwner, activeEntitlements) |
| `backend/app/Services/AccountOwnerResolver.php` | Create | shared owner resolution + per-request memo |
| `backend/app/Providers/AppServiceProvider.php` | Modify | `scoped(AccountOwnerResolver)` |
| `backend/Modules/SubscriptionPlan/app/Services/StripeSubscriptionStatuses.php` | Create | LIVE / DUNNING / PAYMENT_SIGNAL + `providesPaymentSignal()` |
| `backend/app/Listeners/StripeWebhookListener.php` | Modify | drop marker (`:493`), `$isTrialing`, dead `isTrialingSubscription()`, `'metadata' => null` (`:343`, keep monotonic expiry); `:263` → `LIVE` |
| `backend/app/Http/Middleware/EnsureSubscriptionActive.php` | Modify | `gateFor(...)->isBlocked` |
| `backend/app/Http/Controllers/AuthController.php` | Modify | `:308` → `narrativeFor` |
| `backend/Modules/Tenant/app/Http/Controllers/TenantProvisioningController.php` | Modify | `:65` → `narrativeFor` |
| `backend/Modules/Configuration/app/Http/Controllers/ConfigurationController.php` | Modify | inject resolver, delete private copy, literals → constants; `presentTrials()` `:623` adopts `CalendarDays::until` |
| `backend/Modules/SubscriptionPlan/app/Http/Controllers/BillingCheckoutController.php` | Modify | resolver + shared constant. `SubscriptionPaymentController` deliberately UNTOUCHED (divergent-behavior variant, pinned) |
| `frontend/src/shared/utils/dateHelper.ts` | Modify | DELETE `daysUntil` (reverses the earlier addition — the API now owns the number) |
| `frontend/src/shared/utils/__tests__/dateHelper.test.ts` | Modify | UPDATE (not delete): drop the 4 `daysUntil` cases (intent moves to backend feature tests), repoint to remaining helpers |
| `frontend/src/shared/auth/Session.ts` | Modify | narrative type gains `trial_days_left?: number \| null` |
| `frontend/src/.../SelectPlanPage.tsx` | Modify | read `subscription?.trial_days_left` (drop `useMemo :459-462` + `daysUntil` import; `trialDaysLeft` prop plumbing to `:631` unchanged); HOIST derivations + the two `useState` (`:510-511`) above the `:481` early return (pre-existing `rules-of-hooks` ERROR, fixed here) |
| `frontend/src/.../UpcomingChargeBanner.tsx` | Modify | `:29` reads `trial_days_left` directly; drop `daysUntil` import + React-Compiler comment |
| `frontend/src/.../SelectPlanPage.test.tsx` | Modify | fixtures add `trial_days_left` where copy asserts day counts |
| `backend/tests/Feature/Billing/*` | Modify | rewrite SIX suites + new cases (see Testing Strategy) |

## Interfaces / Contracts

```php
final readonly class SubscriptionGate
{
    public function __construct(
        public bool $isBlocked,
        public string $status,          // none|exempt|blocked|entitled
        public bool $isOwner,
        /** @var Collection<int, TenantEntitlement> loaded once; narrative reuses */
        public Collection $activeEntitlements,
    ) {}
}
```

Narrative array keys: `status`, `is_blocked`, `is_owner`, `payment_issue`, optional `expires_at`, `trial_ends_at`, `trial_auto_charges`, **`trial_days_left`** (int|null; trial status only, null when expiry is null).

```php
final class CalendarDays
{
    /** Whole calendar days from today (app tz) to $end's date; 0 today; never negative; null-safe. */
    public static function until(?CarbonInterface $end): ?int;
}
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Feature | gate query count ≤ pre-change AND zero `subscriptions`-table queries | `DB::listen` assertion (spec's "equals" scenario needs the matching correction) |
| Feature | narrative `is_blocked` ≡ gate value across five states | `SubscriptionStateCompositionTest` — the invariant's named enforcement |
| Feature | Stripe trial date; day-31 conversion w/ perpetual entitlement; courtesy trial; `past_due`; canceled+`PENDIENTE_PAGO` pin; entitled+`PENDIENTE_PAGO` STILL true (no-op pin) | rewrite the SIX suites: `SubscriptionStateAccessTest`, `StripeWebhookEntitlementTest`, `SubscriptionEnforcementTest`, `OwnerSubscriptionLifecycleTest`, `SubscriptionPaymentFlowTest`, `AdminTenantProvisionTest` |
| Feature | provisioning stays tenant-scoped: `AdminTenantProvisionTest` `:99-100`, `:132-133`, `:176-177`, `:207` pin the non-exempt payload; ADD one case with a REAL `SLUG_SUPER_ADMIN` role — `superAdminToken()` (`:383-394`) authorizes by token ability, so today NO test can catch an exempt regression there | new test case |
| Feature | `StripeWebhookEntitlementTest.php:255` `assertNull($entitlement->metadata)` INVERTS once `'metadata' => null` is dropped — rewrite to assert the marker is never written | targeted rewrite |
| Fixture debt | `SubscriptionStateAccessTest` lacks the `DB::table('subscriptions')->delete()` in `setUp()` that seven sibling suites have; any NEW Cashier-row fixture there must add it | budgeted for tasks |
| Unit | `AppTimezoneCast` idempotency: UTC Carbon → local; already-local Carbon → unchanged; re-save → unchanged (double-normalization trap) | cast unit test |
| Feature | webhook-driven `trial_ends_at`/`ends_at`/`user.trial_ends_at` writes agree with Eloquent-written `created_at` (local wall time) | Billing suites |
| Feature | `trial_days_left`: day one of a 30-day trial → 30 (NOT 31); expiry day → 0; null expiry → null; courtesy trial uses entitlement expiry — inherits the four deleted FE `daysUntil` cases | narrative tests (`Carbon::setTestNow`) |
| Feature | `presentTrials.days_left` ≡ `CalendarDays::until` (no drift between the two consumers) | ConfigurationController test |
| Unit (FE) | remaining date helpers only (`daysUntil` gone) | updated `__tests__/dateHelper.test.ts` |
| Regression | backend `--filter=Billing` ≥ 280 (name-filter, NOT directory-scoped), frontend ≥ 760 tests / 99 files | same commands as baseline, single process, host-DB overrides |

Notes: ZERO existing tests break from the `payment_issue` predicate itself (the five relevant assertions live in `SubscriptionStateAccessTest`; none creates a Cashier row, and the entitled branch is a no-op). `SubscriptionPaymentFlowTest.php:692` does NOT break — its site is excluded from the resolver extraction.

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary (route allowlist untouched; only a method call changes inside existing middleware).

## Migration / Rollout

ONE data migration (`normalize_stripe_timestamp_timezones`): converts existing skewed rows of the 3 columns from UTC wall time to app-tz wall time; ledger-guaranteed single run; reversible `down()`. Acceptable as one-shot: user confirmed "no hay nada en prod". No schema change. Stale `stripe_trialing` rows go inert once unread. Rollback = discard hunks + `migrate:rollback` (nothing committed; hunk-level, base carries `signup-requires-plan-and-card` WIP). Worktree `backend/.claude/worktrees/fix+billing-base-scope-menu/` holds stale copies — never touch.

## Open Questions

- [ ] None blocking. (Spec correction in flight, routed by the orchestrator: query-count scenario "equals pre-change" → "≤ pre-change AND no `subscriptions` queries"; `/config/subscription` removed from narrative consumers; `SubscriptionPaymentFlowTest:692` mischaracterization in `stripe-trial-billing` spec.)
