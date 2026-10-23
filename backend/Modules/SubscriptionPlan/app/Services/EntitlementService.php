<?php

namespace Modules\SubscriptionPlan\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Module\Models\Module;
use Modules\Setting\Models\Setting;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModule;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\System\Services\ModuleTenantService;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Database\TenantScope;

/**
 * Projects a tenant's ACTIVE billing entitlements (tenant_entitlement rows) onto
 * the access tables the rest of the platform reads:
 *
 *   - system_tenant  (which parent systems the organization sees in the menu)
 *   - module_tenant  (per-module opt-out flags consumed by VerifyModuleEnabled)
 *   - setting         (per-tenant config rows of revoked modules are purged)
 *
 * This service is STANDALONE (Slice 1 / A2): it does NOT replace
 * SystemTenantService::syncFromPlan — that rewrite is Slice 2. The parity test
 * proves recompute() yields the SAME system_tenant set that syncFromPlan
 * produces today, so the two can be reconciled later without surprises.
 *
 * It NEVER touches Stripe. Granted modules are derived from the billing packages
 * of the tenant's active entitlements, mapped to their parent systems via
 * module_system.
 *
 * Tenant scoping: every write targets an EXPLICIT $tenant (by tenant_id), not the
 * ambient TenantScope — recompute() can run for a tenant other than the one the
 * request initialized (e.g. a Stripe webhook, a backfill command, a cron).
 */
class EntitlementService
{
    public function __construct(
        private ModuleTenantService $moduleTenantService,
    ) {}

    /**
     * The module_system / billing_package_module / system_tenant pivots live on
     * the central connection. Force it explicitly so the projector works even
     * after tenancy middleware switched the default connection (mirrors
     * ModuleTenantService).
     */
    private function centralConnection(): string
    {
        return config('tenancy.database.central_connection', 'pgsql');
    }

    /**
     * Recompute system_tenant + module_tenant + config for a tenant from its
     * active entitlements. Idempotent: running it twice with the same
     * entitlements yields the same access state.
     */
    public function recompute(Tenant $tenant): void
    {
        DB::transaction(function () use ($tenant): void {
            $grantedModuleIds = $this->grantedModuleIds($tenant);

            $this->syncSystemTenant($tenant, $this->grantedSystemIds($grantedModuleIds));
            $this->syncModuleTenant($tenant, $grantedModuleIds);
            $this->purgeConfigForRevokedModules($tenant, $grantedModuleIds);
        });
    }

    /**
     * Upsert a PER-MODULE entitlement for the tenant and (optionally) recompute its
     * access state. One entitlement row = one granted billable module ($module),
     * carrying the plan ($plan, a billing_package: basico..premium) it was granted
     * under.
     *
     * Pass $recompute=false to defer the projection rebuild when granting several
     * modules in one batch (e.g. a Stripe webhook with multiple subscription
     * items): the caller then runs recompute() ONCE after all grants instead of
     * once per grant. recompute() is idempotent, so the final access state is
     * identical either way.
     *
     * @param  array{interval?: ?string, quantity?: int, expires_at?: mixed, stripe_subscription_id?: ?string, stripe_item_id?: ?string, metadata?: ?array<string, mixed>}  $options
     *
     * @throws InvalidArgumentException
     */
    public function grant(Tenant $tenant, BillableModule $module, BillingPackage $plan, string $source, array $options = [], bool $recompute = true): TenantEntitlement
    {
        if (! in_array($source, TenantEntitlement::SOURCES, true)) {
            throw new InvalidArgumentException("Invalid entitlement source: {$source}.");
        }

        return DB::transaction(function () use ($tenant, $module, $plan, $source, $options, $recompute): TenantEntitlement {
            $existing = TenantEntitlement::query()
                ->where('tenant_id', $tenant->id)
                ->where('billable_module_id', $module->id)
                ->first();

            // A2 — CENTRALIZED clobber guard. A non-stripe grant (super_admin_free,
            // trial, legacy) must NEVER overwrite an ACTIVE stripe entitlement: the
            // grant() upsert is keyed by (tenant_id, billable_module_id), so it would
            // flip source→non-stripe and NULL the stripe_subscription_id while Stripe
            // keeps charging the organization — the platform would lose the billing link.
            // Leave the live stripe row intact and return it unchanged; the operator
            // must cancel the Stripe subscription first (which expires the row, after
            // which a free grant is allowed). A stripe→stripe grant (renewal/reconcile)
            // and a grant over an expired/absent row proceed normally.
            if ($existing !== null
                && $source !== TenantEntitlement::SOURCE_STRIPE
                && $existing->source === TenantEntitlement::SOURCE_STRIPE
                && ($existing->expires_at === null || $existing->expires_at->greaterThan(now()))) {
                return $existing;
            }

            $metadata = $options['metadata'] ?? null;

            // Atomic INSERT ... ON CONFLICT (tenant_id, billable_module_id) DO UPDATE.
            // updateOrCreate's read-then-write races under concurrent Octane workers
            // (grant() is called from Stripe webhooks); a single upsert leans on the
            // unique constraint so two simultaneous grants can't both insert.
            // upsert bypasses casts, so metadata (array cast) is encoded here.
            TenantEntitlement::upsert(
                [[
                    'tenant_id' => $tenant->id,
                    'billable_module_id' => $module->id,
                    'billing_package_id' => $plan->id,
                    'source' => $source,
                    'interval' => $options['interval'] ?? null,
                    'quantity' => $options['quantity'] ?? 1,
                    'expires_at' => $options['expires_at'] ?? null,
                    'stripe_subscription_id' => $options['stripe_subscription_id'] ?? null,
                    'stripe_item_id' => $options['stripe_item_id'] ?? null,
                    'metadata' => $metadata !== null ? json_encode($metadata) : null,
                ]],
                ['tenant_id', 'billable_module_id'],
                ['billing_package_id', 'source', 'interval', 'quantity', 'expires_at', 'stripe_subscription_id', 'stripe_item_id', 'metadata'],
            );

            $entitlement = TenantEntitlement::query()
                ->where('tenant_id', $tenant->id)
                ->where('billable_module_id', $module->id)
                ->firstOrFail();

            if ($recompute) {
                $this->recompute($tenant);
            }

            return $entitlement;
        });
    }

    /**
     * Whether the tenant has EVER held any entitlement of any source, including
     * expired and soft-deleted rows (withTrashed). D1 — the single-Stripe-trial
     * lock: MultiItemSubscriptionService only grants a checkout trial when this
     * is false, so a organization cannot re-trial by cancelling and re-subscribing.
     * withTrashed() is required because reconcileSubscriptionEntitlements
     * soft-deletes the rows of a removed subscription item — a plain exists()
     * would miss that history and re-enable the trial.
     */
    public function tenantEverHadEntitlement(Tenant $tenant): bool
    {
        return TenantEntitlement::withTrashed()
            ->where('tenant_id', $tenant->id)
            ->exists();
    }

    /**
     * Start an INTERNAL free trial of a module for the tenant under its CURRENT
     * plan (tenant.billing_package_id). Grants a source=trial entitlement expiring
     * in (plan_module.trial_days ?? config('billing.trial_days')) days. NO
     * Stripe: this is a courtesy try-before-you-buy, reconciled by the normal
     * expiry cron. recompute() runs inside grant() so the module turns on at once.
     *
     * Guards (every domain failure is an InvalidArgumentException → 422 at the
     * controller):
     *   (a) the module must be OFFERED by the tenant's plan — the
     *       interval-independent 2NF fact, answered by plan_module row
     *       existence for (plan, module).
     *   (b) to trial an ADD-ON, the tenant's BASE module must already be active
     *       (paid / courtesy / trial) — an add-on cannot be tried with no base.
     *   (c) ONE trial per module per tenant — a historical trial (even expired,
     *       which is NEVER deleted) bars a second one.
     *   (d) the module must not already be active for the tenant.
     *
     * @throws InvalidArgumentException
     */
    public function startModuleTrial(Tenant $tenant, string $moduleKey): TenantEntitlement
    {
        $plan = $tenant->billing_package_id !== null
            ? BillingPackage::query()->whereKey($tenant->billing_package_id)->first()
            : null;

        if ($plan === null) {
            throw new InvalidArgumentException('La organización no tiene un plan asignado para iniciar una prueba.');
        }

        $module = BillableModule::query()
            ->where('key', $moduleKey)
            ->where('is_active', true)
            ->first();

        if ($module === null) {
            throw new InvalidArgumentException("Módulo inválido o inactivo: {$moduleKey}.");
        }

        // (a) The module must be offered by the tenant's plan — the
        // interval-independent 2NF fact; this same row is what trial_days is
        // read from below.
        $planModule = PlanModule::query()
            ->where('billing_package_id', $plan->id)
            ->where('billable_module_id', $module->id)
            ->first();

        if ($planModule === null) {
            throw new InvalidArgumentException('Este módulo no está disponible en tu plan.');
        }

        // (d) The module must not already be active.
        $alreadyActive = TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->where('billable_module_id', $module->id)
            ->active()
            ->exists();

        if ($alreadyActive) {
            throw new InvalidArgumentException('Este módulo ya está activo en tu organización.');
        }

        // (c) One trial per module per tenant (expired trials are kept, not deleted).
        $alreadyTrialed = TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->where('billable_module_id', $module->id)
            ->where('source', TenantEntitlement::SOURCE_TRIAL)
            ->exists();

        if ($alreadyTrialed) {
            throw new InvalidArgumentException('Ya usaste la prueba gratuita de este módulo.');
        }

        // (b) To trial an add-on, the base module must be active.
        if (! $module->is_base) {
            $baseModule = BillableModule::query()->where('is_base', true)->first();

            $baseActive = $baseModule !== null && TenantEntitlement::query()
                ->where('tenant_id', $tenant->id)
                ->where('billable_module_id', $baseModule->id)
                ->active()
                ->exists();

            if (! $baseActive) {
                throw new InvalidArgumentException('La organización debe tener su plan activo antes de iniciar una prueba.');
            }
        }

        // Explicit month-preferred pluck over the pair's ACTIVE price rows —
        // identical observable value to the old orderBy('interval')->first()
        // trick (month wins alphabetically) for every well-formed dataset.
        // Null only in the drift-only state: a plan_module row exists but the
        // pair has zero active price rows (a trial sells nothing; publication
        // is a separate, interval-specific concern).
        $intervals = PlanModulePrice::query()
            ->where('billing_package_id', $plan->id)
            ->where('billable_module_id', $module->id)
            ->where('is_active', true)
            ->pluck('interval');

        $interval = $intervals->contains(PlanModulePrice::INTERVAL_MONTH)
            ? PlanModulePrice::INTERVAL_MONTH
            : $intervals->first();

        $trialDays = $planModule->trial_days ?? (int) config('billing.trial_days');

        return $this->grant(
            $tenant,
            $module,
            $plan,
            TenantEntitlement::SOURCE_TRIAL,
            [
                'interval' => $interval,
                'expires_at' => now()->addDays($trialDays),
            ],
        );
    }

    /**
     * Distinct platform-module ids granted by the tenant's ACTIVE entitlements
     * (union of every active billable module's system modules, via the
     * billable_module_system_module pivot).
     *
     * @return Collection<int, string>
     */
    private function grantedModuleIds(Tenant $tenant): Collection
    {
        return TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->active()
            ->with('billable_module.systemModules:id,name')
            ->get()
            ->flatMap(fn (TenantEntitlement $entitlement): Collection => $entitlement->billable_module
                ? $entitlement->billable_module->systemModules->pluck('id')
                : collect())
            ->unique()
            ->values();
    }

    /**
     * Parent systems of the granted modules (via module_system).
     *
     * @param  Collection<int, string>  $grantedModuleIds
     * @return Collection<int, string>
     */
    private function grantedSystemIds(Collection $grantedModuleIds): Collection
    {
        if ($grantedModuleIds->isEmpty()) {
            return collect();
        }

        return collect(
            DB::connection($this->centralConnection())
                ->table('module_system')
                ->whereIn('module_id', $grantedModuleIds->all())
                ->distinct()
                ->pluck('system_id')
        );
    }

    /**
     * Attach the granted parent systems with is_addon=false and detach the plan
     * systems that are no longer granted (orphans). Manual add-ons (is_addon=true)
     * are NEVER touched.
     *
     * Final state — the is_addon=false set becomes (grantedSystemIds − addons) —
     * is identical to SystemTenantService::syncFromPlan, which is what the parity
     * test asserts.
     *
     * @param  Collection<int, string>  $grantedSystemIds
     */
    private function syncSystemTenant(Tenant $tenant, Collection $grantedSystemIds): void
    {
        $planSystemIds = $tenant->systems()->wherePivot('is_addon', false)->pluck('system.id');

        $orphans = $planSystemIds->diff($grantedSystemIds);

        if ($orphans->isNotEmpty()) {
            $tenant->systems()->detach($orphans->all());
        }

        // Re-read after detaching so we never attach a system already present
        // (whether as a plan system or a manual add-on).
        $currentlyAttached = $tenant->systems()->pluck('system.id');

        $toAttach = $grantedSystemIds
            ->diff($currentlyAttached)
            ->mapWithKeys(fn (string $id): array => [$id => ['is_addon' => false]]);

        if ($toAttach->isNotEmpty()) {
            $tenant->systems()->attach($toAttach->all());
        }
    }

    /**
     * Set module_tenant.enabled for every BILLABLE platform module (one that maps
     * to a billable_module via billable_module_system_module): true when granted,
     * false otherwise. Modules outside the billing catalog are left to the opt-out
     * default (enabled).
     *
     * @param  Collection<int, string>  $grantedModuleIds
     */
    private function syncModuleTenant(Tenant $tenant, Collection $grantedModuleIds): void
    {
        $billableModuleIds = DB::connection($this->centralConnection())
            ->table('billable_module_system_module')
            ->distinct()
            ->pluck('module_id');

        if ($billableModuleIds->isEmpty()) {
            return;
        }

        $entries = $billableModuleIds
            ->map(fn (string $moduleId): array => [
                'module_id' => $moduleId,
                'enabled' => $grantedModuleIds->contains($moduleId),
            ])
            ->all();

        $this->moduleTenantService->syncBulk($tenant, $entries);
    }

    /**
     * Soft-delete the per-tenant Setting rows that belong to modules NOT in the
     * granted set, so a downgraded organization stops carrying config for features it
     * no longer has. Operates on the EXPLICIT tenant (bypassing TenantScope) so it
     * is safe to run for any tenant regardless of the ambient request context.
     *
     * @param  Collection<int, string>  $grantedModuleIds
     */
    protected function purgeConfigForRevokedModules(Tenant $tenant, Collection $grantedModuleIds): void
    {
        $prefixMap = $this->moduleSettingPrefixes();

        if ($prefixMap === []) {
            return;
        }

        $configModules = Module::query()
            ->whereIn('permission_entity', array_keys($prefixMap))
            ->get(['id', 'permission_entity']);

        $revokedPrefixes = $configModules
            ->reject(fn (Module $module): bool => $grantedModuleIds->contains($module->id))
            ->flatMap(fn (Module $module): array => $prefixMap[$module->permission_entity] ?? [])
            ->unique()
            ->values();

        if ($revokedPrefixes->isEmpty()) {
            return;
        }

        Setting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where(function ($query) use ($revokedPrefixes): void {
                foreach ($revokedPrefixes as $prefix) {
                    $query->orWhere('slug', 'like', $prefix.'%');
                }
            })
            ->delete();
    }

    /**
     * Map of module permission_entity (the STABLE English identifier — same key
     * Orion controllers expose as $keyForPermission, and what
     * ModuleTenantService resolves by) to the Setting slug prefixes that module
     * owns. Keyed by permission_entity rather than the Spanish display `name` so a
     * UI rename never silently breaks config purging. Kept deliberately small and
     * explicit (KISS); a subclass/config can extend it. Settings are keyed by
     * dotted slug prefixes such as "collections." / "package." (see
     * ConfigurationController). The mapped modules are exactly the ones the base
     * and package billing packages include (BillingPackageSeeder: "Cobros" →
     * charge_batch, "Paquetería" → package).
     *
     * @return array<string, array<int, string>>
     */
    protected function moduleSettingPrefixes(): array
    {
        return [
            'charge_batch' => ['collections.'],
            'package' => ['package.'],
        ];
    }
}
