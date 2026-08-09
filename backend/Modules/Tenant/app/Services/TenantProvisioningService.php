<?php

namespace Modules\Tenant\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Modules\SubscriptionPlan\Exceptions\CheckoutUnavailableException;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\SubscriptionPayment;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\EntitlementService;
use Modules\SubscriptionPlan\Services\PlanPricingService;
use Modules\SubscriptionPlan\Services\SubscriptionPaymentService;
use Modules\Tenant\Models\Tenant;

/**
 * Super-admin ALTA of a brand-new cluster WITH its billing provisioned, in one
 * server-authored step (billing v2, per-module + plan).
 *
 * Access is driven by tenant_entitlement rows (SubscriptionStateService). Every
 * grant is PER billable module, carrying the PLAN (billing_package: basico..
 * premium) it was granted under. The four provision modes:
 *
 *   - trial       → a base-module LOCAL, card-free trial (source=trial) lasting
 *                   config('billing.trial_days') days. This is the ONLY producer
 *                   of source=trial for a whole cluster (per-module add-on
 *                   trials are a separate path, EntitlementService::
 *                   startModuleTrial) — self-registration no longer grants one;
 *                   a self-registered owner's trial lives in Stripe Checkout
 *                   instead (source=stripe, MultiItemSubscriptionService).
 *   - courtesy    → the chosen modules granted FREE, perpetually
 *                   (source=super_admin_free, no expiry, $0).
 *   - manual_paid → a real out-of-band payment (transfer/cash) recorded for the
 *                   chosen modules, amounts computed server-side by
 *                   PlanPricingService (source=manual, one billing cycle).
 *   - card        → NO entitlements: the cluster stays blocked until the owner
 *                   self-checks-out with Stripe.
 *
 * Amounts are ALWAYS computed by PlanPricingService — client-supplied amounts are
 * never trusted. Billing prerequisites (plan, modules, base presence, prices) are
 * resolved BEFORE the tenant is created so bad input never leaves an orphan cluster.
 */
class TenantProvisioningService
{
    public const MODE_TRIAL = 'trial';

    public const MODE_COURTESY = 'courtesy';

    public const MODE_MANUAL_PAID = 'manual_paid';

    public const MODE_CARD = 'card';

    public const MODES = [
        self::MODE_TRIAL,
        self::MODE_COURTESY,
        self::MODE_MANUAL_PAID,
        self::MODE_CARD,
    ];

    public function __construct(
        private readonly EntitlementService $entitlementService,
        private readonly SubscriptionPaymentService $subscriptionPaymentService,
        private readonly PlanPricingService $planPricingService,
    ) {}

    /**
     * Create the cluster and provision its billing for the chosen mode.
     *
     * @param  array<string, mixed>  $data  the validated provision payload
     *
     * @throws InvalidArgumentException on invalid mode/method combos, an invalid or
     *                                  inactive plan/module, a missing base module, or
     *                                  a manual payment with nothing to charge
     */
    public function provision(array $data, User $actor): Tenant
    {
        $mode = $data['provision_mode'];
        $paymentMethod = $data['payment_method'];

        $this->assertMethodMatchesMode($mode, $paymentMethod);

        $units = isset($data['units']) ? (int) $data['units'] : 1;
        $interval = $data['interval'] ?? PlanModulePrice::INTERVAL_MONTH;

        // Resolve every billing prerequisite BEFORE creating the tenant so invalid
        // input never leaves an orphan cluster behind.
        $plan = $this->resolvePlan($data['plan_key'] ?? null, $units);
        $baseModule = $this->planPricingService->baseModule();

        // A price-less (consultative) plan cannot be provisioned through a mode that
        // charges: there is nothing to bill. Courtesy and trial are free, so they may
        // legitimately run on it.
        if (in_array($mode, [self::MODE_CARD, self::MODE_MANUAL_PAID], true)
            && $this->planPricingService->isContactSales($plan)) {
            throw CheckoutUnavailableException::contactSales(
                'El plan "'.$plan->name.'" se cotiza a la medida; no puede cobrarse automáticamente.'
            );
        }

        $modules = collect();
        $manualLines = [];

        if ($mode === self::MODE_COURTESY || $mode === self::MODE_MANUAL_PAID) {
            $modules = $this->resolveModules((array) ($data['modules'] ?? []));
            $this->assertHasBase($modules);
        }

        if ($mode === self::MODE_MANUAL_PAID) {
            $manualLines = $this->buildManualLines($modules, $plan, $interval);
        }

        $tenant = Tenant::create($this->tenantAttributes($data, $mode, $paymentMethod, $plan));

        match ($mode) {
            self::MODE_TRIAL => $this->grantTrial($tenant, $baseModule, $plan),
            self::MODE_COURTESY => $this->grantCourtesy($tenant, $modules, $plan),
            self::MODE_MANUAL_PAID => $this->subscriptionPaymentService->recordManual(
                $tenant,
                $manualLines,
                $paymentMethod,
                now(),
                $data['notes'] ?? null,
                $actor,
            ),
            self::MODE_CARD => null,
        };

        return $tenant->fresh();
    }

    /**
     * Only the real, provided columns are set — timezone/currency/uses_ownership_share
     * are omitted when absent so their DB defaults apply. subscription_plan_id is
     * deliberately NEVER set (access is by entitlements); billing_package_id records
     * the plan the cluster is on.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function tenantAttributes(array $data, string $mode, string $paymentMethod, BillingPackage $plan): array
    {
        $attributes = [
            'name' => $data['name'],
            'status' => $this->statusForMode($mode),
            'payment_method' => $paymentMethod,
            'billing_package_id' => $plan->id,
        ];

        foreach (['timezone', 'currency', 'type', 'uses_ownership_share'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                $attributes[$key] = $data[$key];
            }
        }

        return $attributes;
    }

    private function statusForMode(string $mode): string
    {
        return $mode === self::MODE_CARD
            ? Tenant::STATUS_PENDIENTE_PAGO
            : Tenant::STATUS_ACTIVO;
    }

    private function grantTrial(Tenant $tenant, BillableModule $baseModule, BillingPackage $plan): void
    {
        $this->entitlementService->grant(
            $tenant,
            $baseModule,
            $plan,
            TenantEntitlement::SOURCE_TRIAL,
            [
                'expires_at' => now()->addDays(config('billing.trial_days')),
                'metadata' => ['reason' => 'admin_onboarding_trial'],
            ],
        );
    }

    /**
     * Grant each chosen module free and perpetual under the plan, recomputing access
     * ONCE at the end (grant() defers its own recompute).
     *
     * @param  Collection<int, BillableModule>  $modules
     */
    private function grantCourtesy(Tenant $tenant, Collection $modules, BillingPackage $plan): void
    {
        foreach ($modules as $module) {
            $this->entitlementService->grant(
                $tenant,
                $module,
                $plan,
                TenantEntitlement::SOURCE_SUPER_ADMIN_FREE,
                ['expires_at' => null],
                recompute: false,
            );
        }

        $this->entitlementService->recompute($tenant);
    }

    /**
     * Build the manual-payment lines with server-computed amounts. Modules with no
     * priced cell in the plan (free/pending) are skipped. The base module is always
     * priced, so the list is never empty.
     *
     * @param  Collection<int, BillableModule>  $modules
     * @return array<int, array{module: BillableModule, plan: BillingPackage, amount: int, interval: string}>
     */
    private function buildManualLines(Collection $modules, BillingPackage $plan, string $interval): array
    {
        $lines = [];

        foreach ($modules as $module) {
            try {
                $amount = $this->planPricingService->priceForModule($plan, $module, $interval);
            } catch (InvalidArgumentException) {
                continue;
            }

            if ($amount < 1) {
                continue;
            }

            $lines[] = [
                'module' => $module,
                'plan' => $plan,
                'amount' => $amount,
                'interval' => $interval,
            ];
        }

        if ($lines === []) {
            throw new InvalidArgumentException('No hay módulos con precio para cobrar en el pago manual.');
        }

        return $lines;
    }

    /**
     * Resolve the plan by explicit key, else by unit band.
     */
    private function resolvePlan(?string $planKey, int $units): BillingPackage
    {
        if ($planKey !== null && $planKey !== '') {
            // Contact-sales plans resolve here so a COURTESY provision can reference
            // them; the charging modes are rejected in provision() with a 422.
            $plan = $this->planPricingService->planByKey($planKey, includeContactSales: true);

            if ($plan === null) {
                throw new InvalidArgumentException("Plan inválido o inactivo: {$planKey}.");
            }

            return $plan;
        }

        return $this->planPricingService->planForUnits($units);
    }

    /**
     * Resolve the selected module keys to ACTIVE billable modules. An EMPTY
     * selection means "the whole plan": modules are no longer a user-facing
     * choice, so the super-admin wizard may omit them entirely.
     *
     * @param  array<int, string>  $keys
     * @return Collection<int, BillableModule>
     *
     * @throws InvalidArgumentException on an unknown/inactive key
     */
    private function resolveModules(array $keys): Collection
    {
        $keys = array_values(array_unique(array_filter($keys)));

        if ($keys === []) {
            return $this->allActiveModules();
        }

        $modules = BillableModule::query()
            ->whereIn('key', $keys)
            ->where('is_active', true)
            ->get();

        $missing = collect($keys)->diff($modules->pluck('key'));

        if ($missing->isNotEmpty()) {
            throw new InvalidArgumentException('Módulo inválido o inactivo: '.$missing->implode(', ').'.');
        }

        return $modules;
    }

    /**
     * Every sellable module (today: just the base). The default when the caller
     * sends no explicit selection.
     *
     * @return Collection<int, BillableModule>
     *
     * @throws InvalidArgumentException when nothing is sellable
     */
    private function allActiveModules(): Collection
    {
        $modules = BillableModule::query()
            ->where('is_active', true)
            ->orderByDesc('is_base')
            ->orderBy('sort_order')
            ->get();

        if ($modules->isEmpty()) {
            throw new InvalidArgumentException('No hay módulos facturables configurados.');
        }

        return $modules;
    }

    /**
     * @param  Collection<int, BillableModule>  $modules
     *
     * @throws InvalidArgumentException when no base module is present
     */
    private function assertHasBase(Collection $modules): void
    {
        $hasBase = $modules->contains(fn (BillableModule $module): bool => (bool) $module->is_base);

        if (! $hasBase) {
            throw new InvalidArgumentException('La provisión debe incluir el plan base.');
        }
    }

    /**
     * Defense in depth (the FormRequest already enforces this): a manual paid plan
     * cannot be recorded on card, and the card mode requires the card method.
     *
     * @throws InvalidArgumentException
     */
    private function assertMethodMatchesMode(string $mode, string $paymentMethod): void
    {
        if ($mode === self::MODE_MANUAL_PAID
            && ! in_array($paymentMethod, SubscriptionPayment::MANUAL_METHODS, true)) {
            throw new InvalidArgumentException('Un pago manual registrado requiere método de pago transferencia o efectivo.');
        }

        if ($mode === self::MODE_CARD && $paymentMethod !== Tenant::PAYMENT_METHOD_CARD) {
            throw new InvalidArgumentException('El modo "el dueño paga con tarjeta" requiere método de pago tarjeta.');
        }
    }
}
