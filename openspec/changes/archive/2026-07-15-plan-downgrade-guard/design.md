# Design: Plan Downgrade Guard

## Technical Approach

Actor-aware guard at the controller seam: `ConfigurationController::changeBillingPlan` hard-blocks (422) and `previewBillingPlanChange` pre-warns (200 + warning block) when the tenant's current active unit count exceeds the target plan's `suggested_unit_max`. The check is a new inverse method on `UnitLimitService` (shipped `f25114e`), reusing its tenant-filtered count, the `UnitLimitCheck` DTO, and `UnitLimitExceededException` — no parallel types (proposal lock). `PreferentialSwapService` behavior is untouched (docblock only), preserving the locked super-admin bypass.

## Architecture Decisions

### Decision 1: Guard location — controller, not service

| Option | Tradeoff | Decision |
|---|---|---|
| Inside `PreferentialSwapService::applyCard/applyManual` | Catches every caller — but BOTH actors funnel there (`ConfigurationController` :1354/:1362, `PreferentialSwapController` :89/:96) → blocks the super admin, violating the locked bypass | Rejected |
| `ConfigurationController::changeBillingPlan` + `previewBillingPlanChange` only | A future route calling the service directly bypasses the guard | **Chosen** |

Precedent at this exact seam, same file: the `changeBillingPlan` docblock (:1337) — "is_public is enforced here, not in the service". Accepted cost of the bypass decision: the service stays guardless. Mitigation: extend the `PreferentialSwapService` class docblock — "unit-band fit is deliberately NOT checked here: the cluster-admin surface enforces it in ConfigurationController; the super-admin preferential swap is exempt by user decision" — so nobody "helpfully" moves it down. The super-admin exemption is already bounded by the `tokenCan('update.tenant')` constructor gate (`PreferentialSwapController` :37-45); no redundant check added.

### Decision 2: Inverse check on `UnitLimitService`

New `checkPlanFits(BillingPackage $target): UnitLimitCheck` + `assertPlanFits(BillingPackage $target): void` (mirrors the shipped `checkIncrease`/`assertCanIncrease` pair). Reuses `activeUnitCount()` (its `Unit::count()` is tenant-scoped via `BelongsToTenant`; no query duplicated, no `tenant_entitlement` touched — the target band comes from the caller). DTO reuse with target semantics: `planName`/`unitLimit` = TARGET's, `attemptedIncrease` = 0 (documented). Fail-open when tenancy uninitialized, mirroring `checkIncrease` (the config routes always have tenancy — `tenant()->getTenantKey()` at :1348). Suggestion reuses private `suggestPlanName`, generalized to an optional exclude-plan-id: `checkIncrease` keeps excluding the current band; plan-fit passes null (suggesting the tenant's CURRENT plan is informative here — "the plan that fits is the one you already have"). Its existing fits-check already neutralizes `planForUnits`' clamp-to-largest: above 300 units it returns null, never a non-fitting Premium.

### Decision 3: Rule boundary

`blocked ⇔ $target->suggested_unit_max !== null && $activeUnits > $target->suggested_unit_max`. Exactly-at-limit fits (25 units → Básico max 25 passes). NULL max = unlimited = fits (future Enterprise). Direction-agnostic predicate — upgrades/same-band pass because their cap covers the count; no direction detection code.

### Decision 4: Surfacing

| Surface | Choice | Why |
|---|---|---|
| Apply | `assertPlanFits($context['to'])` inside the try, after context resolution; add `catch (UnitLimitExceededException $e) { return $e->render(); }` BEFORE the `catch (Throwable)` | The Throwable catch (:1366) would otherwise swallow it — logging routine business behavior (defeating `ShouldntReport`) and flattening the payload to "No se pudo cambiar el plan." |
| Preview | NO 422. After building `$payload`, `checkPlanFits`; when not allowed, append `$payload['unit_limit_warning']` | FE parses with `preferentialSwapPreviewSchema.parse` (`CheckoutSubscriptionResource.ts` :187) — a 422 lands in the modal's error panel as a FAILED preview, losing proration data. A warning keeps the preview coherent: numbers + "won't apply". **The schema is a plain non-strict `z.object` (`schema.ts:175`) — NOT `.strict()`, NOT `.passthrough()` — so Zod's default STRIP applies: an unknown `unit_limit_warning` is silently dropped, never thrown. That default is load-bearing: do NOT reach for `z.strictObject`/`.strict()`, which would break this approach. Note the sibling schema at `schema.ts:173` does use `.passthrough()`, so the codebase distinguishes these deliberately.** Because strip is silent, the field MUST be added to the schema or the FE simply never sees it (the same class of bug as the import truncation notice: backend sent it, FE dropped it, nobody noticed). The super-admin swap preview shares this exact schema (`TenantBillingResource.ts:85`) and simply never receives the field — `.optional()` keeps both consumers compatible |

FE consumers: `CheckoutSubscriptionResource.previewBillingPlanChange/changeBillingPlan` → `ChangeBillingPlanModal.tsx` (`modules/user/components/`) via `useSubscriptionChange`. The apply-422 path needs ZERO FE changes — the hook already toasts the backend `message`. Modal additionally renders the warning panel and disables "Confirmar cambio" when `unit_limit_warning` is present (UI belt; apply re-check is the suspenders — covers units added between preview and apply).

### Decision 5: Copy

One builder, `UnitLimitService::buildPlanChangeMessage(UnitLimitCheck): string` (mirrors shipped `buildImportPreviewMessage`), used by the preview warning and passed into `UnitLimitExceededException::forPlanChange($check, $message)` (second named constructor; `forIncrease`'s copy reads "agregar 0 más" here — wrong). Same `code` `UNIT_LIMIT_EXCEEDED` (FE resolves message-first; nothing branches on code). Mexican Spanish, tú:

- Fits exists: `"Tu clúster tiene {active_units} unidades activas y el plan {plan_name} admite hasta {unit_limit}. El plan que se ajusta es {suggested_plan_name}."`
- No fitting plan (above top band): `"… Contacta a soporte para encontrar una opción a tu medida."`

Name-only suggestion (no band range) — consistent with the shipped sibling copy and the DTO's `?string`.

## Data Flow

    Modal ──preview──▶ previewBillingPlanChange
        ├─ resolveBillingPlanChangeContext → target
        ├─ previewCard|previewManual → payload
        └─ checkPlanFits(target) → !allowed → payload.unit_limit_warning
    ◀─ 200 — warning panel + Confirm disabled

    Confirm ──apply──▶ changeBillingPlan
        ├─ assertPlanFits(target) ─throws─▶ catch → $e->render() → 422
        └─ fits → applyCard|applyManual (unchanged)

    PreferentialSwapController ──▶ PreferentialSwapService (UNTOUCHED) — bypass

## File Changes

| File | Action | Description |
|---|---|---|
| `backend/Modules/SubscriptionPlan/app/Services/UnitLimitService.php` | Modify | `checkPlanFits`, `assertPlanFits`, `buildPlanChangeMessage`; generalize `suggestPlanName` exclusion |
| `backend/Modules/SubscriptionPlan/app/Exceptions/UnitLimitExceededException.php` | Modify | `forPlanChange` constructor (payload without `attempted_increase`) |
| `backend/Modules/SubscriptionPlan/app/Services/UnitLimitCheck.php` | Modify | **Docblock only — REQUIRED, not optional.** The DTO's current class docblock (`:13-18`) is FALSE under target semantics and must be corrected: it states `planName`/`unitLimit` "are null exactly when the guard is fail-open … see `UnitLimitService::resolveBand()`" (under `checkPlanFits` they carry the TARGET plan's name/limit, which never comes from `resolveBand()` — it comes from the caller), and that `suggestedPlanName` "is null when there is no fitting plan **above the current band** … or when the guard already allowed the increase" (under `checkPlanFits` the exclusion is null, so the suggestion may legitimately BE the tenant's current plan). Document both readings — increase semantics vs target semantics — and that `attemptedIncrease = 0` is meaningless under target semantics, which is why `forPlanChange` omits it from the payload. Without this, the DTO documents the opposite of what it carries |
| `backend/Modules/SubscriptionPlan/app/Services/PreferentialSwapService.php` | Modify | Docblock only — record deliberate exemption |
| `backend/Modules/Configuration/app/Http/Controllers/ConfigurationController.php` | Modify | Guard insertions + dedicated catch; method-inject `UnitLimitService` in both methods |
| `frontend/src/modules/tenant/billing/schema.ts` | Modify | Optional `unit_limit_warning` in `preferentialSwapPreviewSchema` |
| `frontend/src/modules/user/components/ChangeBillingPlanModal.tsx` | Modify | Warning panel + Confirm disabled |
| `backend/Modules/SubscriptionPlan/tests/Unit/UnitLimitServiceTest.php` | Modify | Fit matrix |
| `backend/tests/Feature/Billing/PlanDowngradeGuardTest.php` | Create | Endpoint matrix + bypass proof |
| `frontend/src/modules/user/components/ChangeBillingPlanModal.test.tsx` | Create | Warning render + disabled Confirm |

## Interfaces / Contracts

```php
public function checkPlanFits(BillingPackage $target): UnitLimitCheck;   // planName/unitLimit = TARGET's; attemptedIncrease = 0
public function assertPlanFits(BillingPackage $target): void;            // @throws UnitLimitExceededException
public function buildPlanChangeMessage(UnitLimitCheck $check): string;
public static function forPlanChange(UnitLimitCheck $check, string $message): self;
```

```ts
unit_limit_warning: z.object({
  message: z.string(),
  plan_name: z.string().nullable(),
  unit_limit: z.number().nullable(),
  active_units: z.number(),
  suggested_plan_name: z.string().nullable(),
}).optional()
```

**The preview warning deliberately carries NO `code`** (unlike the apply-path 422 payload, which keeps `UNIT_LIMIT_EXCEEDED`). Rationale: nothing branches on `code` — the FE resolves message-first (`apiClient.ts:57` reads `payload?.message`), and the modal only needs to know the warning is present in order to render the panel and disable Confirm. Adding a code here would be dead weight the FE schema still has to declare. Stated explicitly so the implementer does not guess either way.

The five fields above are exactly `UnitLimitCheck`'s payload minus `attempted_increase`, which is meaningless under target semantics (see the `UnitLimitCheck.php` docblock row in File Changes).

## Testing Strategy

| Layer | What to Test | Approach |
|---|---|---|
| Unit (extend `UnitLimitServiceTest`; 11/11 baseline preserved — existing PUBLIC methods' BEHAVIOR preserved; `suggestPlanName` (`UnitLimitService.php:182`) has its signature generalized and its call site in `checkIncrease` (:136) updated — that is the one real touch-point on sibling code, and the 11/11 baseline is what guards it) | over-cap blocks; exactly-at-max fits; NULL max fits; uninitialized fail-open; suggestion = smallest fitting band; clamp above top band → null; message variants | HOST php, ONE process (`pgrep -f "artisan test"` first) |
| Feature (new `PlanDowngradeGuardTest`) | apply over-band → 422 payload + `billing_package_id` unchanged; upgrade → 200; preview over-band → `unit_limit_warning`; preview fitting → key absent; super-admin swap + preview on the same over-band tenant → 200 (bypass proof) | `SelfServeBillingPlanTest` fixture pattern (its plans use `suggested_unit_max=100`, zero units created → count 0 fits → existing suite unaffected) |
| FE component | Warning copy renders; Confirm disabled; no-warning path unchanged | Vitest + Testing Library, colocated |
| Regression | `SelfServeBillingPlanTest`, `PreferentialSwapTest`, `Modules/DataImport/tests` (124/475) | Scoped, sequential |

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary (two existing endpoints change behavior; no new routes).

## Migration / Rollout

No migration. Purely additive; revert removes the guard calls + methods. Not in production → no grandfathering (locked).

## Open Questions

None blocking.
