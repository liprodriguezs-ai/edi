<?php

namespace Modules\Configuration\Http\Controllers;

use App\Models\User;
use App\Rules\MexicanRfc;
use App\Services\AccountOwnerResolver;
use App\Support\CalendarDays;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;
use Laravel\Cashier\Subscription;
use Modules\Collections\Support\DelinquencySettings;
use Modules\Company\Models\Company;
use Modules\Company\Services\CompanyService;
use Modules\Configuration\Database\Seeders\NotificationTemplateSeeder;
use Modules\Configuration\Models\LegalDocument;
use Modules\Housing\Models\Section;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitFeature;
use Modules\Notification\Services\NotificationSettingService;
use Modules\Setting\Models\Setting;
use Modules\SubscriptionPlan\Exceptions\CheckoutUnavailableException;
use Modules\SubscriptionPlan\Exceptions\UnitLimitExceededException;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\MultiItemSubscriptionService;
use Modules\SubscriptionPlan\Services\PlanPricingService;
use Modules\SubscriptionPlan\Services\PreferentialSwapService;
use Modules\SubscriptionPlan\Services\QuotaService;
use Modules\SubscriptionPlan\Services\StripeSubscriptionStatuses;
use Modules\SubscriptionPlan\Services\UnitLimitService;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use QCH\Core\Support\TenantMoney;
use RuntimeException;
use Throwable;

/**
 * ConfigurationController — Module 12: Cluster Configuration (spec §16).
 *
 * Decision: folded into the Configuration module (new, no domain tables besides
 * the versioned legal_document). Edits are routed to existing tables:
 *   - general/branding → company (legal/branding identity + contact + logo/cover)
 *   - operational      → tenant (timezone, currency, type, uses_ownership_share)
 *   - collection       → setting table (collections.* tenant-scoped key-value)
 *   - templates        → setting table (notification_template.* JSON keys + is_active)
 *   - legal            → legal_document (versioned, media-backed)
 *   - structure        → read-only summary over Housing
 *
 * Authorization: every WRITE requires update.configuration; every privileged READ
 * requires read.configuration (Administrator/Account Owner, plus board reads as
 * seeded). Resident-accessible bits (current aviso de privacidad, the
 * bank-data-visible flag effect on the public summary) are gated only by auth.
 */
class ConfigurationController extends Controller
{
    public function __construct(private readonly AccountOwnerResolver $ownerResolver) {}

    // -------------------------------------------------------------------------
    // Authorization helpers
    // -------------------------------------------------------------------------

    /**
     * Require the given ability on the current Sanctum token (wildcard passes).
     *
     * @throws AuthorizationException
     */
    private function requireAbility(string $ability): void
    {
        $user = auth()->guard('sanctum')->user();

        if (! $user || ! ($user->tokenCan($ability) || $user->tokenCan('*'))) {
            throw new AuthorizationException('Unauthorized action.');
        }
    }

    /**
     * F-18 — ACCOUNT-OWNER gate for billing MUTATIONS (portal, cancel, resume,
     * change-billing-plan). manage.subscription alone is not enough: the seeded
     * `administrator` role carries it, so a hired manager could change the card,
     * cancel the cluster's subscription, or switch its plan — while
     * /billing/checkout already required the owner. Same 403 contract as checkout
     * (`code: not_account_owner`) so the frontend can tell this apart from a plain
     * permission failure.
     *
     * Returns a ready-to-return JsonResponse when the caller is not the owner, null
     * when they are. Deliberately ISOLATED in one helper and applied at four call
     * sites, so reverting it (should the product owner prefer manage.subscription
     * semantics) is a four-line deletion.
     *
     * READS (GET /config/subscription) are intentionally NOT gated: a treasurer or
     * administrator must still be able to see what the cluster pays.
     */
    private function requireAccountOwner(Tenant $tenant): ?JsonResponse
    {
        $owner = $this->ownerResolver->resolve($tenant);

        if (! $owner) {
            return response()->json([
                'message' => 'No se encontró el propietario de la cuenta para este condominio.',
                'code' => 'owner_unresolved',
            ], 422);
        }

        $user = auth()->guard('sanctum')->user();

        if (! $user || $owner->id !== $user->id) {
            return response()->json([
                'message' => 'Solo el propietario de la cuenta puede administrar la suscripción del condominio.',
                'code' => 'not_account_owner',
            ], 403);
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // General (company legal/branding identity + contact)
    // -------------------------------------------------------------------------

    public function getGeneral(): JsonResponse
    {
        $this->requireAbility('read.configuration');

        $company = $this->mainCompanyForTenant();

        // cluster_name lives on the TENANT, not the company: tenant.name is the
        // label the whole app shows as the cluster (site header, cluster switcher,
        // resident home). It rides along in this section because to an admin it is
        // simply "the name of my condominium", next to the rest of its identity.
        $payload = $this->presentCompany($company) ?? [];
        $payload['cluster_name'] = tenant()?->name;

        return response()->json(['data' => $payload]);
    }

    /**
     * Resolve THIS tenant's main company.
     *
     * Company is a central model (no BelongsToTenant — it is also a super-admin
     * Orion resource and is created/read outside tenant context during
     * onboarding). A bare Company::where('main', true)->first() in a shared DB
     * returns the FIRST main company across ALL tenants — a cross-tenant leak of
     * cluster identity. The model's main() scope adds the tenant_id filter when
     * tenancy is initialized (and stays unscoped for super-admin/onboarding).
     */
    private function mainCompanyForTenant(): ?Company
    {
        return Company::query()->main()->first();
    }

    /**
     * Company payload enriched with the current logo URL (§16.3.2).
     *
     * @return array<string, mixed>|null
     */
    private function presentCompany(?Company $company): ?array
    {
        if (! $company) {
            return null;
        }

        $payload = $company->toArray();
        $payload['logo_url'] = app(CompanyService::class)->logoUrl($company);

        return $payload;
    }

    public function updateGeneral(Request $request): JsonResponse
    {
        $this->requireAbility('update.configuration');

        $request->validate([
            // 'sometimes' + 'required' = optional key, but never blank when sent:
            // an empty cluster name would leave the whole UI with no cluster label.
            'cluster_name' => ['sometimes', 'required', 'string', 'max:255'],
            'name' => ['sometimes', 'string', 'max:255'],
            // legal_name and fiscal_address are NULLABLE columns: a cluster that
            // has not filled in its legal identity yet sends them as null, and
            // rejecting that 422'd the whole section (rename included).
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'rfc' => ['sometimes', 'nullable', 'string', 'max:20', new MexicanRfc],
            'fiscal_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'short_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $company = $this->mainCompanyForTenant();

        if (! $company) {
            return response()->json(['message' => 'Main company not found for this tenant.'], 404);
        }

        $clusterName = $request->has('cluster_name')
            ? trim((string) $request->input('cluster_name'))
            : null;

        DB::transaction(function () use ($request, $company, $clusterName): void {
            $company->fill($request->only([
                'name', 'legal_name', 'rfc', 'fiscal_address',
                'phone', 'email', 'website', 'short_name', 'tagline',
            ]));

            if ($clusterName !== null) {
                $tenant = Tenant::findOrFail(tenant()->getTenantKey());
                $tenant->name = $clusterName;
                $tenant->save();

                // company.name is seeded FROM tenant.name by
                // CompanyService::ensureMainCompanyForTenant, so the two start out
                // equal and mean the same thing (the cluster's commercial name).
                // Renaming only the tenant would silently leave a stale copy behind.
                // Applied AFTER fill() so the explicit cluster_name always wins over
                // a legacy 'name' key in the same payload.
                $company->name = $clusterName;
            }

            $company->save();
        });

        $payload = $this->presentCompany($company->fresh()) ?? [];
        $payload['cluster_name'] = $clusterName ?? tenant()?->name;

        return response()->json(['data' => $payload]);
    }

    // -------------------------------------------------------------------------
    // Branding media (cluster logo upload) — §16.3.2
    // -------------------------------------------------------------------------

    /**
     * Attach an uploaded image to the main company's single-file `logo` collection.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $this->requireAbility('update.configuration');

        $request->validate([
            'file' => ['required', 'image', 'max:5120'],
        ]);

        $company = $this->mainCompanyForTenant();

        if (! $company) {
            return response()->json(['message' => 'Main company not found for this tenant.'], 404);
        }

        $company->addMedia($request->file('file'))->toMediaCollection('logo');

        return response()->json([
            'message' => 'Imagen actualizada.',
            'data' => [
                'logo_url' => app(CompanyService::class)->logoUrl($company->refresh()),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Operational (tenant real columns)
    // -------------------------------------------------------------------------

    public function getOperational(): JsonResponse
    {
        $this->requireAbility('read.configuration');

        $tenant = tenant();

        return response()->json([
            'data' => [
                'timezone' => $tenant?->timezone,
                'currency' => $tenant?->currency,
                'type' => $tenant?->type,
                'uses_ownership_share' => $tenant?->uses_ownership_share,
            ],
        ]);
    }

    public function updateOperational(Request $request): JsonResponse
    {
        $this->requireAbility('update.configuration');

        $request->validate([
            'timezone' => ['sometimes', 'string', 'timezone'],
            'currency' => ['sometimes', 'string', Rule::in(['MXN', 'USD'])],
            'type' => ['sometimes', Rule::in(['horizontal', 'vertical', 'mixed'])],
            'uses_ownership_share' => ['sometimes', 'boolean'],
            'confirmed' => ['required_with:timezone,currency', 'boolean'],
        ]);

        // Spec §16.4 rule 2: timezone/currency change requires explicit confirmation.
        if (($request->has('timezone') || $request->has('currency')) && ! $request->boolean('confirmed')) {
            return response()->json([
                'message' => 'El cambio de zona horaria o moneda requiere confirmación explícita. Envía confirmed=true para proceder.',
                'requires_confirmation' => true,
            ], 422);
        }

        $tenant = Tenant::findOrFail(tenant()->getTenantKey());
        $tenant->fill($request->only(['timezone', 'currency', 'type', 'uses_ownership_share']));
        $tenant->save();

        return response()->json([
            'data' => $request->only(['timezone', 'currency', 'type', 'uses_ownership_share']),
        ]);
    }

    // -------------------------------------------------------------------------
    // Collection settings (Setting table, tenant-scoped key-value)
    // -------------------------------------------------------------------------

    public function getCollectionSettings(): JsonResponse
    {
        $this->requireAbility('read.configuration');

        $keys = [
            'collections.charge_generation_day',
            'collections.due_soon_days',
            'collections.grace_days',
            'collections.reminders',
            'collections.physical_receipt',
            'collections.payment_methods_enabled',
            'collections.bank_data_visible_to_residents',
            'collections.next_physical_folio',
        ];

        $settings = Setting::whereIn('slug', $keys)->get()->keyBy('slug');

        $data = [];
        foreach ($keys as $key) {
            $setting = $settings->get($key);
            $data[$key] = $setting?->resolved_value;
        }

        return response()->json(['data' => $data]);
    }

    public function updateCollectionSettings(Request $request): JsonResponse
    {
        $this->requireAbility('update.configuration');

        // The setting slugs contain literal dots ("collections.due_soon_days").
        // Laravel's validator + $request->input() treat a dot in a key as nested
        // array traversal, so validating/reading them by the dotted key silently
        // yields nothing (the historical no-op bug: PUT 200 but data:[]). The
        // dotted keys ARE preserved verbatim in $request->all(), so we read each
        // one from there by its literal key, then validate the flat payload with a
        // RULE-KEY map whose dots are escaped (\.) so the validator does NOT treat
        // them as nested. This makes the values actually persist.
        $rules = [
            'collections.charge_generation_day' => ['sometimes', 'integer', 'min:1', 'max:28'],
            'collections.due_soon_days' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'collections.grace_days' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'collections.reminders' => ['sometimes', 'array'],
            'collections.physical_receipt' => ['sometimes', 'boolean'],
            'collections.payment_methods_enabled' => ['sometimes', 'array'],
            'collections.bank_data_visible_to_residents' => ['sometimes', 'boolean'],
            'collections.next_physical_folio' => ['sometimes', 'string', 'max:50'],
        ];

        $all = $request->all();

        // §16.4 r7: turning bank data visible to residents requires double confirm.
        if (
            array_key_exists('collections.bank_data_visible_to_residents', $all)
            && filter_var($all['collections.bank_data_visible_to_residents'], FILTER_VALIDATE_BOOLEAN)
            && ! $request->boolean('double_confirm')
        ) {
            return response()->json([
                'message' => 'Mostrar datos bancarios a residentes requiere doble confirmación. Envía double_confirm=true para proceder.',
                'requires_confirmation' => true,
            ], 422);
        }

        // Only the keys the client actually sent (preserves "sometimes" semantics).
        $present = array_intersect_key($all, $rules);

        // Validate against escaped rule keys so the dot stays literal, not nested.
        $escapedRules = [];
        foreach ($rules as $slug => $rule) {
            $escapedRules[str_replace('.', '\.', $slug)] = $rule;
        }
        validator($present, $escapedRules)->validate();

        // The escaped rule keys above keep the literal dots in the slug, which
        // also neutralizes Laravel's `.*` wildcard — so per-element rules cannot
        // be expressed there. Validate the array items with a dedicated validator
        // on a non-dotted key. Only runs when the client sent the key (preserves
        // "sometimes" semantics); an absent or empty array validates trivially.
        if (array_key_exists('collections.payment_methods_enabled', $present)) {
            validator(
                ['ids' => $present['collections.payment_methods_enabled']],
                ['ids.*' => ['uuid']]
            )->validate();
        }

        $persisted = [];
        foreach ($present as $slug => $value) {
            $type = is_bool($value) ? Setting::TYPE_BOOLEAN
                : (is_array($value) ? Setting::TYPE_JSON
                    : (is_int($value) ? Setting::TYPE_INTEGER : Setting::TYPE_STRING));

            Setting::putValue($slug, $value, $type);

            $persisted[$slug] = $value;
        }

        return response()->json(['message' => 'Configuración de cobranza actualizada.', 'data' => $persisted]);
    }

    /**
     * Resident-accessible: the primary bank account shown on the payment screen,
     * but ONLY when the cluster enabled `bank_data_visible_to_residents` (§16.3.4).
     * Gated by auth only (any tenant user may see it when the cluster allows it).
     */
    public function getResidentBankData(): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();

        if (! $user) {
            throw new AuthorizationException('Unauthorized action.');
        }

        $visible = Setting::getValue('collections.bank_data_visible_to_residents');

        if ($visible !== true) {
            return response()->json([
                'visible' => false,
                'data' => null,
            ]);
        }

        $account = BankAccount::query()
            ->where('is_active', true)
            ->orderByDesc('is_primary')
            ->first();

        return response()->json([
            'visible' => true,
            'data' => $account ? [
                'name' => $account->name,
                'bank' => $account->bank,
                'account_number' => $account->account_number,
                'clabe' => $account->clabe,
            ] : null,
        ]);
    }

    // -------------------------------------------------------------------------
    // Notification templates (Setting table with notification_template.* keys)
    // -------------------------------------------------------------------------

    public function getNotificationTemplates(): JsonResponse
    {
        $this->requireAbility('read.configuration');

        $templates = Setting::where('slug', 'like', 'notification_template.%')
            ->get()
            ->map(function (Setting $setting): array {
                // `value` is natively decoded by the `json` cast — read through it
                // directly, no more raw json_decode() bypassing the accessor.
                $decoded = $setting->value;

                return [
                    'slug' => $setting->slug,
                    'title' => is_array($decoded) ? ($decoded['title'] ?? null) : null,
                    'body' => is_array($decoded) ? ($decoded['body'] ?? null) : (is_string($decoded) ? $decoded : null),
                    'is_active' => is_array($decoded) ? (bool) ($decoded['is_active'] ?? true) : true,
                ];
            })
            ->values();

        return response()->json(['data' => $templates]);
    }

    public function updateNotificationTemplates(Request $request): JsonResponse
    {
        $this->requireAbility('update.configuration');

        $request->validate([
            'templates' => ['required', 'array'],
            'templates.*.slug' => ['required', 'string', 'starts_with:notification_template.'],
            'templates.*.title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'templates.*.body' => ['required', 'string'],
            'templates.*.is_active' => ['sometimes', 'boolean'],
        ]);

        foreach ($request->input('templates') as $tpl) {
            Setting::putValue(
                $tpl['slug'],
                [
                    'title' => $tpl['title'] ?? '',
                    'body' => $tpl['body'],
                    'is_active' => array_key_exists('is_active', $tpl)
                        ? filter_var($tpl['is_active'], FILTER_VALIDATE_BOOLEAN)
                        : true,
                ],
                Setting::TYPE_JSON
            );
        }

        return response()->json(['message' => 'Plantillas actualizadas.']);
    }

    // -------------------------------------------------------------------------
    // Structure summary (read-only over Housing) — §16.3.3
    // -------------------------------------------------------------------------

    public function getStructure(): JsonResponse
    {
        $this->requireAbility('read.configuration');

        return response()->json([
            'data' => [
                'type' => tenant()?->type,
                'towers_count' => Tower::query()->count(),
                'sections_count' => Section::query()->count(),
                'unit_features_count' => UnitFeature::query()->count(),
                'units_count' => Unit::query()->count(),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Subscription self-service (§16.2 'Gestionar suscripción — Adm ✅', §16.3.7)
    // -------------------------------------------------------------------------

    /**
     * Tenant status + the cluster's MULTI-ITEM subscription (base + add-ons billed
     * as one Stripe subscription, projected onto tenant_entitlement), so the cluster
     * administrator can manage the subscription without a super admin.
     *
     * Gated by manage.subscription (seeded to administrator/account_owner only).
     *
     * `current_plan` describes the plan the cluster is CURRENTLY entitled to,
     * derived from its ACTIVE entitlements REGARDLESS of source (stripe, manual,
     * super_admin_free, trial, legacy_plan) — null only when there is no active
     * entitlement at all. The `multi_item` block stays Stripe-only (it describes a
     * card subscription); a cluster paying out of band has a current_plan and no
     * multi_item, and must NOT be told "Sin plan activo".
     *
     * `trials` lists the cluster's ACTIVE per-module free trials (source=trial),
     * separate from the paid `multi_item` block (which only ever describes Stripe
     * lines), so the owner can see which modules are on a trial and when each one
     * expires (expiry-banner data). Always present (empty array when there are none).
     */
    public function getSubscription(): JsonResponse
    {
        $this->requireAbility('manage.subscription');

        $tenant = Tenant::findOrFail(tenant()->getTenantKey());

        $unitCount = Unit::query()->count();

        $multiItem = $this->presentMultiItemSubscription($tenant);

        return response()->json([
            'data' => [
                'status' => $tenant->status,
                'current_plan' => $this->presentCurrentPlan($tenant),
                'unit_count' => $unitCount,
                'subscription' => $this->presentCashierSubscription($tenant),
                'multi_item' => $multiItem,
                'is_multi_item' => $multiItem !== null,
                'trials' => $this->presentTrials($tenant),
                // trial-usage-quotas (REQ-QV-01): reuses $unitCount already
                // computed above — never re-queried inside summary().
                'quotas' => app(QuotaService::class)->summary(liveUnitCount: $unitCount),
            ],
        ]);
    }

    /**
     * The cluster's ACTIVE per-module trials (source=trial), ordered by nearest
     * expiry first, shaped for the owner's expiry banner. Orphan rows (a
     * soft-deleted billable module) are skipped — like the multi-item presenter,
     * a line with no module cannot be shown meaningfully.
     *
     * `days_left` is the whole calendar days from today to the expiry date (0 when
     * it expires today; never negative, since an already-expired trial is not
     * active()). Null expiry (not expected for trials) yields a null days_left.
     *
     * @return array<int, array{module_key: string, module_name: string, plan_key: string|null, plan_name: string|null, expires_at: string|null, days_left: int|null}>
     */
    private function presentTrials(Tenant $tenant): array
    {
        return $this->activeTrialEntitlements($tenant)
            ->map(function (TenantEntitlement $entitlement): ?array {
                $module = $entitlement->billable_module;

                if ($module === null) {
                    return null;
                }

                $plan = $entitlement->billing_package;
                $expiresAt = $entitlement->expires_at;

                return [
                    'module_key' => $module->key,
                    'module_name' => $module->name,
                    'plan_key' => $plan?->key,
                    'plan_name' => $plan?->name,
                    'expires_at' => $expiresAt?->toIso8601String(),
                    'days_left' => CalendarDays::until($expiresAt),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The tenant's ACTIVE trial entitlements with their module + plan loaded,
     * nearest-expiry first. Mirrors activeStripeEntitlements() but scoped to
     * source=trial.
     *
     * @return Collection<int, TenantEntitlement>
     */
    private function activeTrialEntitlements(Tenant $tenant): Collection
    {
        return TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->where('source', TenantEntitlement::SOURCE_TRIAL)
            ->active()
            ->with(['billable_module', 'billing_package'])
            ->orderBy('expires_at')
            ->get();
    }

    /**
     * Describe the tenant's MULTI-ITEM Stripe subscription (plan + modules) from
     * its ACTIVE stripe entitlements, or null when there is none. Amounts are
     * server-authoritative (PlanPricingService::priceForModule, minor units); the
     * client is never trusted for money.
     *
     * @return array{plan: array{key: string, name: string}|null, modules: array<int, array<string, mixed>>, interval: string, total: int, currency: string}|null
     */
    private function presentMultiItemSubscription(Tenant $tenant): ?array
    {
        $entitlements = $this->activeStripeEntitlements($tenant);

        if ($entitlements->isEmpty()) {
            return null;
        }

        $state = $this->currentMultiItemState($entitlements);
        $interval = $state['interval'];
        $plan = $state['plan'];
        $pricingService = app(PlanPricingService::class);

        $modules = [];
        $total = 0;
        $currency = 'MXN';

        foreach ($entitlements->sortBy(fn (TenantEntitlement $e): int => (int) ($e->billable_module?->sort_order ?? 0)) as $entitlement) {
            $module = $entitlement->billable_module;

            if (! $module || ! $plan) {
                continue;
            }

            try {
                $subtotal = $pricingService->priceForModule($plan, $module, $interval);
            } catch (InvalidArgumentException) {
                // A misconfigured cell (no price at this interval) is described at 0
                // rather than breaking the whole read.
                $subtotal = 0;
            }

            $modules[] = [
                'key' => $module->key,
                'name' => $module->name,
                'is_base' => (bool) $module->is_base,
                'interval' => $entitlement->interval ?? $interval,
                'subtotal' => $subtotal,
            ];

            $total += $subtotal;
        }

        return [
            'plan' => $plan !== null ? ['key' => $plan->key, 'name' => $plan->name] : null,
            'modules' => $modules,
            'interval' => $interval,
            'total' => $total,
            'currency' => $currency,
        ];
    }

    /**
     * The tenant's ACTIVE stripe entitlements with their module + plan loaded.
     *
     * @return Collection<int, TenantEntitlement>
     */
    private function activeStripeEntitlements(Tenant $tenant): Collection
    {
        return $this->activeEntitlements($tenant, TenantEntitlement::SOURCE_STRIPE);
    }

    /**
     * The tenant's ACTIVE entitlements (optionally narrowed to one source) with
     * their module + plan loaded.
     *
     * @return Collection<int, TenantEntitlement>
     */
    private function activeEntitlements(Tenant $tenant, ?string $source = null): Collection
    {
        return TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->when($source !== null, fn ($query) => $query->where('source', $source))
            ->active()
            ->with(['billable_module', 'billing_package'])
            ->get();
    }

    /**
     * The plan the cluster is CURRENTLY entitled to, from its ACTIVE entitlements
     * of ANY source — the answer to "¿qué plan tengo?" for a card cluster, a
     * cluster paying by transfer/cash, a courtesy grant and a trial alike. Null
     * only when no entitlement is active (then, and only then, the UI may say
     * "Sin plan activo").
     *
     * `total` is server-authoritative (never the client's): the list price of the
     * plan's active lines at the entitlement's interval, and 0 for the sources
     * that are free by definition (courtesy / trial) so a gift is never presented
     * as a charge. Null when the interval is unknown, since a price cannot be
     * derived without one.
     *
     * @return array{key: string, name: string, source: string, interval: string|null, total: int|null, currency: string, expires_at: string|null}|null
     */
    private function presentCurrentPlan(Tenant $tenant): ?array
    {
        $entitlements = $this->activeEntitlements($tenant);

        if ($entitlements->isEmpty()) {
            return null;
        }

        // The base line carries the plan the cluster contracted; fall back to any
        // line that has one (an orphan row with no plan cannot describe anything).
        $primary = $entitlements->first(fn (TenantEntitlement $e): bool => (bool) ($e->billable_module?->is_base) && $e->billing_package !== null)
            ?? $entitlements->first(fn (TenantEntitlement $e): bool => $e->billing_package !== null);

        $plan = $primary?->billing_package;

        if ($primary === null || $plan === null) {
            return null;
        }

        return [
            'key' => $plan->key,
            'name' => $plan->name,
            'source' => $primary->source,
            'interval' => $primary->interval,
            'total' => $this->currentPlanTotal($entitlements, $primary),
            'currency' => 'MXN',
            'expires_at' => $primary->expires_at?->toIso8601String(),
        ];
    }

    /**
     * Total charged for the cluster's current plan, in minor units.
     *
     * @param  Collection<int, TenantEntitlement>  $entitlements
     */
    private function currentPlanTotal(Collection $entitlements, TenantEntitlement $primary): ?int
    {
        $freeSources = [TenantEntitlement::SOURCE_SUPER_ADMIN_FREE, TenantEntitlement::SOURCE_TRIAL];

        if (in_array($primary->source, $freeSources, true)) {
            return 0;
        }

        $interval = $primary->interval;
        $plan = $primary->billing_package;

        if ($interval === null || $plan === null) {
            return null;
        }

        $pricingService = app(PlanPricingService::class);
        $total = 0;

        foreach ($entitlements as $entitlement) {
            $module = $entitlement->billable_module;

            if ($module === null || $entitlement->billing_package?->id !== $plan->id) {
                continue;
            }

            try {
                $total += $pricingService->priceForModule($plan, $module, $interval);
            } catch (InvalidArgumentException) {
                // A misconfigured cell (no price at this interval) contributes 0
                // rather than breaking the whole read — same policy as the
                // multi-item presenter.
            }
        }

        return $total;
    }

    /**
     * Derive the shared multi-item state from a set of entitlements: the module map
     * (keyed by module key), the base module, the plan and the interval. Every line
     * shares one plan + interval (taken from the base line, falling back to any).
     *
     * @param  Collection<int, TenantEntitlement>  $entitlements
     * @return array{modules: Collection<string, BillableModule>, base: BillableModule|null, plan: BillingPackage|null, interval: string}
     */
    private function currentMultiItemState(Collection $entitlements): array
    {
        $modules = $entitlements
            ->map(fn (TenantEntitlement $e): ?BillableModule => $e->billable_module)
            ->filter()
            ->keyBy('key');

        $baseEntitlement = $entitlements->first(
            fn (TenantEntitlement $e): bool => (bool) ($e->billable_module?->is_base)
        );

        $plan = $baseEntitlement?->billing_package
            ?? $entitlements->map(fn (TenantEntitlement $e): ?BillingPackage => $e->billing_package)->filter()->first();

        $interval = $baseEntitlement?->interval
            ?? $entitlements->pluck('interval')->filter()->first()
            ?? PlanModulePrice::INTERVAL_MONTH;

        return [
            'modules' => $modules,
            'base' => $baseEntitlement?->billable_module,
            'plan' => $plan,
            'interval' => $interval,
        ];
    }

    /**
     * Validated frontend base URL (no trailing slash) for Stripe success/cancel/
     * return URLs. A malformed APP_FRONTEND_URL (e.g. a doubled scheme) would
     * otherwise surface as an opaque Stripe "Not a valid URL" 500; fail with a
     * clear, logged message instead.
     */
    private function frontendBaseUrl(): string
    {
        $url = rtrim((string) config('app.frontend_url'), '/');

        $isValid = filter_var($url, FILTER_VALIDATE_URL) !== false
            && substr_count($url, '://') === 1
            && preg_match('#^https?://#', $url) === 1;

        if (! $isValid) {
            Log::error('APP_FRONTEND_URL is malformed; cannot build Stripe redirect URLs.', [
                'frontend_url' => $url,
            ]);

            abort(500, 'La URL del frontend (APP_FRONTEND_URL) está mal configurada.');
        }

        return $url;
    }

    /**
     * Real Cashier subscription data for the tenant's account owner, or null when
     * the owner has no `default` subscription. Used by GET /config/subscription
     * and echoed back from cancel.
     *
     * @return array<string, mixed>|null
     */
    private function presentCashierSubscription(Tenant $tenant): ?array
    {
        $owner = $this->ownerResolver->resolve($tenant);
        $subscription = $owner?->subscription('default');

        if (! $subscription) {
            return null;
        }

        $currentPeriodEnd = null;
        $currentPeriodStart = null;

        // Stripe API 2025-08-27.basil (Cashier::STRIPE_VERSION) moved the period
        // fields off the top-level Subscription onto each SubscriptionItem, so
        // asStripeSubscription()->current_period_end is now always null. Cashier's
        // currentPeriodEnd()/Start() helpers read the items (and may hit Stripe),
        // so wrap them — a Stripe outage must NOT break this read endpoint.
        try {
            $currentPeriodEnd = $subscription->currentPeriodEnd()?->toIso8601String();
            $currentPeriodStart = $subscription->currentPeriodStart()?->toIso8601String();
        } catch (Throwable) {
            $currentPeriodEnd = null;
            $currentPeriodStart = null;
        }

        return [
            'stripe_status' => $subscription->stripe_status,
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'ends_at' => $subscription->ends_at?->toIso8601String(),
            // Canceled but still inside the paid period (will not renew).
            'cancel_at_period_end' => $subscription->onGracePeriod() && $subscription->canceled(),
            'current_period_start' => $currentPeriodStart,
            'current_period_end' => $currentPeriodEnd,
        ];
    }

    // -------------------------------------------------------------------------
    // Stripe billing portal / cancel (real billing)
    // -------------------------------------------------------------------------

    /**
     * Stripe billing portal URL for the owner to manage payment methods /
     * invoices. Ensures the owner has a Stripe customer first.
     */
    public function subscriptionPortal(): JsonResponse
    {
        $this->requireAbility('manage.subscription');

        $tenant = Tenant::findOrFail(tenant()->getTenantKey());

        if ($denied = $this->requireAccountOwner($tenant)) {
            return $denied;
        }

        $owner = $this->ownerResolver->resolve($tenant);

        try {
            $owner->createOrGetStripeCustomer();
        } catch (Throwable) {
            // Stripe network/availability failure → retry-able (503), not a conflict.
            return response()->json([
                'message' => 'No se pudo preparar la cuenta de facturación. Intenta de nuevo en unos minutos.',
            ], 503);
        }

        $returnUrl = $this->frontendBaseUrl().'/subscription';

        return response()->json(['url' => $owner->billingPortalUrl($returnUrl)]);
    }

    /**
     * Cancel the owner's subscription — ALWAYS at period end (grace period). The
     * cluster keeps full access until the paid period ends, then the subscription
     * lapses. Immediate cancellation is intentionally NOT offered; this is enforced
     * here (not just in the UI) so it cannot be bypassed via the API. Reactivation
     * during the grace period is available via resumeSubscription().
     */
    public function cancelSubscription(): JsonResponse
    {
        $this->requireAbility('manage.subscription');

        $tenant = Tenant::findOrFail(tenant()->getTenantKey());

        if ($denied = $this->requireAccountOwner($tenant)) {
            return $denied;
        }

        $owner = $this->ownerResolver->resolve($tenant);
        $subscription = $owner?->subscription('default');

        if (! $subscription) {
            return response()->json([
                'message' => 'No hay una suscripción para cancelar.',
            ], 404);
        }

        // At period end only — keep access until the paid period ends.
        $subscription->cancel();

        return response()->json([
            'message' => 'La suscripción se cancelará al final del período pagado.',
            'data' => $this->presentCashierSubscription($tenant->fresh()),
        ]);
    }

    /**
     * Reactivate (resume) a subscription that is cancelled but still within its
     * grace period (cancel_at_period_end). Once the period has ended the
     * subscription can no longer be resumed and a new checkout is required.
     */
    public function resumeSubscription(): JsonResponse
    {
        $this->requireAbility('manage.subscription');

        $tenant = Tenant::findOrFail(tenant()->getTenantKey());

        if ($denied = $this->requireAccountOwner($tenant)) {
            return $denied;
        }

        $owner = $this->ownerResolver->resolve($tenant);
        $subscription = $owner?->subscription('default');

        if (! $subscription) {
            return response()->json([
                'message' => 'No hay una suscripción para reactivar.',
            ], 404);
        }

        if (! $subscription->onGracePeriod()) {
            return response()->json([
                'message' => 'La suscripción no se puede reactivar. Inicia una nueva suscripción.',
            ], 409);
        }

        $subscription->resume();

        return response()->json([
            'message' => 'Tu suscripción fue reactivada.',
            'data' => $this->presentCashierSubscription($tenant->fresh()),
        ]);
    }

    // -------------------------------------------------------------------------
    // Multi-item self-service management (add-on / units / interval + preview)
    // -------------------------------------------------------------------------
    //
    // Owner-scoped management of the MULTI-ITEM subscription AFTER purchase. Every
    // endpoint: manage.subscription gate → resolve the single account owner + their
    // active Stripe subscription + active stripe entitlements → apply the change via
    // MultiItemSubscriptionService (proration on) → let the
    // customer.subscription.updated webhook reconcile tenant_entitlement
    // (StripeWebhookListener), never writing entitlements here. Amounts are always
    // recomputed server-side (PricingService); the client is never trusted for money.

    /**
     * Preview the proration of a proposed change WITHOUT applying it.
     *
     * Body: { change_type: change_interval, interval }. Returns the signed proration
     * amount (negative = credit), the proration_date to pin on apply, the new
     * recurring total, currency, and effective_at. Reuses PreferentialSwapService's
     * pattern: Stripe previewInvoice of the proposed end state + read-back
     * proration_date.
     *
     * change_interval is the ONLY change type left: the 2026-07 collapse removed
     * sellable add-ons, and the endpoints that applied add_addon / remove_addon are
     * gone — keeping them here was dead surface (F-14).
     */
    public function previewSubscriptionChange(Request $request, MultiItemSubscriptionService $service): JsonResponse
    {
        $this->requireAbility('manage.subscription');

        $validated = $request->validate([
            'change_type' => ['required', Rule::in(['change_interval'])],
            'interval' => ['required', Rule::in(PlanModulePrice::INTERVALS)],
        ]);

        $tenant = Tenant::findOrFail(tenant()->getTenantKey());

        $context = $this->resolveMultiItemContext($tenant);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        try {
            $target = $this->resolveTargetConfiguration($context['entitlements'], $validated);

            $preview = $service->previewChange(
                $context['subscription'],
                $target['plan_key'],
                $target['modules'],
                $target['interval'],
            );
        } catch (CheckoutUnavailableException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason()], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('subscription change preview failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'No se pudo calcular la vista previa del cambio.'], 422);
        }

        return response()->json(['data' => $preview]);
    }

    /**
     * Change the billing interval (month <-> year), cascading to EVERY line
     * (base + add-ons) in one prorated swap. Body: { interval }. Rejects when a
     * current package has no synced price at the destination interval. Proration is
     * computed by Stripe at now — the client cannot pin a proration_date.
     */
    public function changeSubscriptionInterval(Request $request, MultiItemSubscriptionService $service): JsonResponse
    {
        $this->requireAbility('manage.subscription');

        $validated = $request->validate([
            'interval' => ['required', Rule::in(PlanModulePrice::INTERVALS)],
        ]);

        $tenant = Tenant::findOrFail(tenant()->getTenantKey());

        $context = $this->resolveMultiItemContext($tenant);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        $state = $this->currentMultiItemState($context['entitlements']);
        $interval = (string) $validated['interval'];

        if ($interval === $state['interval']) {
            return response()->json([
                'message' => 'Tu suscripción ya usa ese intervalo de cobro.',
                'code' => 'interval_unchanged',
            ], 422);
        }

        // Every current module must have a synced price at the destination interval,
        // or the cascade swap would silently drop a line.
        $priceIds = [];

        foreach ($state['modules'] as $module) {
            $priceId = $this->resolveModuleStripePrice($state['plan'], $module, $interval);

            if ($priceId === null) {
                return response()->json([
                    'message' => 'El intervalo seleccionado no está disponible para tu plan.',
                    'code' => 'interval_unavailable',
                ], 422);
            }

            $priceIds[] = $priceId;
        }

        try {
            $service->changeInterval(
                $context['subscription'],
                array_values(array_unique($priceIds)),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('change subscription interval failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'No se pudo cambiar el intervalo de cobro.'], 422);
        }

        // F-11 — converge the LOCAL entitlement in the same request. Stripe is now on
        // the new interval, but the presenter derives both the displayed interval and
        // the total from tenant_entitlement.interval, so leaving it stale rendered a
        // self-contradicting card ("$7,410 / año" next to a monthly next-charge date)
        // until the webhook arrived — permanently, if it never did. The webhook
        // remains authoritative and simply re-writes the same value.
        // change-billing-plan already updates locally; this makes the pair symmetric.
        TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->where('source', TenantEntitlement::SOURCE_STRIPE)
            ->active()
            ->update(['interval' => $interval]);

        return response()->json([
            'message' => 'Intervalo de cobro actualizado.',
            'data' => $this->presentMultiItemSubscription($tenant->fresh()),
        ]);
    }

    /**
     * Resolve the owner + their ACTIVE multi-item Stripe subscription + active
     * stripe entitlements, or a ready-to-return error. Every self-serve mutation
     * needs all three: an ambiguous/missing owner is a 422, a cluster with no active
     * multi-item subscription is a 409.
     *
     * @return array{owner: User, subscription: Subscription, entitlements: Collection<int, TenantEntitlement>}|JsonResponse
     */
    private function resolveMultiItemContext(Tenant $tenant): array|JsonResponse
    {
        $owner = $this->ownerResolver->resolve($tenant);

        if (! $owner) {
            return response()->json([
                'message' => 'No se encontró el propietario de la cuenta para este condominio.',
                'code' => 'owner_unresolved',
            ], 422);
        }

        $subscription = $owner->subscription('default');
        $entitlements = $this->activeStripeEntitlements($tenant);

        if (
            ! $subscription
            || ! in_array($subscription->stripe_status, StripeSubscriptionStatuses::LIVE, true)
            || $entitlements->isEmpty()
        ) {
            return response()->json([
                'message' => 'No tienes una suscripción activa administrable para este condominio.',
                'code' => 'no_multi_item_subscription',
            ], 409);
        }

        return ['owner' => $owner, 'subscription' => $subscription, 'entitlements' => $entitlements];
    }

    /**
     * Compute the FULL desired {plan, module keys, interval} AFTER applying the
     * requested change, for the proration preview. Shared with the apply endpoint so
     * the preview and the apply describe the same end state.
     *
     * @param  Collection<int, TenantEntitlement>  $entitlements
     * @param  array<string, mixed>  $input  validated {change_type, interval}
     * @return array{plan_key: string, modules: array<int, string>, interval: string}
     *
     * @throws InvalidArgumentException
     */
    private function resolveTargetConfiguration(Collection $entitlements, array $input): array
    {
        $state = $this->currentMultiItemState($entitlements);
        $plan = $state['plan'];

        if ($input['change_type'] !== 'change_interval') {
            throw new InvalidArgumentException('Tipo de cambio no soportado.');
        }

        $interval = (string) $input['interval'];
        $modules = $state['modules']->values();

        $this->guardIntervalAvailable($modules, $plan, $interval);

        return [
            'plan_key' => (string) ($plan?->key ?? ''),
            'modules' => $modules->map(fn (BillableModule $m): string => $m->key)->values()->all(),
            'interval' => $interval,
        ];
    }

    /**
     * Ensure every module has a synced price in the plan at the interval (for the
     * interval cascade preview).
     *
     * @param  Collection<int, BillableModule>  $modules
     *
     * @throws InvalidArgumentException
     */
    private function guardIntervalAvailable(Collection $modules, ?BillingPackage $plan, string $interval): void
    {
        foreach ($modules as $module) {
            if ($this->resolveModuleStripePrice($plan, $module, $interval) === null) {
                throw new InvalidArgumentException('El intervalo seleccionado no está disponible para tu plan.');
            }
        }
    }

    /**
     * The synced Stripe price id of a plan×module cell for an interval, or null when
     * there is no active, synced price.
     */
    private function resolveModuleStripePrice(?BillingPackage $plan, BillableModule $module, string $interval): ?string
    {
        if ($plan === null) {
            return null;
        }

        $price = PlanModulePrice::query()
            ->where('billing_package_id', $plan->id)
            ->where('billable_module_id', $module->id)
            ->where('interval', $interval)
            ->where('is_active', true)
            ->first();

        return $price && $price->stripe_price_id ? $price->stripe_price_id : null;
    }

    // -------------------------------------------------------------------------
    // Billing-plan change (self-serve)
    // -------------------------------------------------------------------------

    /**
     * Owner self-serve: move the cluster to another PUBLIC billing v2 plan, re-mapping
     * EVERY contracted (non-courtesy) module line to the destination plan's prices in
     * ONE prorated swap (CARD), or re-pointing the entitlements at the new plan and
     * recomputing amounts (MANUAL). Delegates the billing mechanics to
     * PreferentialSwapService (the same engine the super-admin preferential swap uses;
     * is_public is enforced here, not in the service). Also updates
     * tenant.billing_package_id (inside the service).
     *
     * plan-downgrade-guard (REQ-01/REQ-08): before delegating, asserts the tenant's
     * current active unit count fits the TARGET plan's band via
     * UnitLimitService::assertPlanFits() — enforced HERE, not inside
     * PreferentialSwapService, so the super-admin preferential swap (same service,
     * different controller) stays exempt. Direction-agnostic: upgrades and
     * same-band reselects always pass.
     */
    public function changeBillingPlan(Request $request, PreferentialSwapService $swapService, UnitLimitService $unitLimitService): JsonResponse
    {
        $this->requireAbility('manage.subscription');

        $validated = $request->validate([
            'plan_key' => ['required', 'string'],
        ]);

        $tenant = Tenant::findOrFail(tenant()->getTenantKey());

        if ($denied = $this->requireAccountOwner($tenant)) {
            return $denied;
        }

        try {
            $context = $this->resolveBillingPlanChangeContext($tenant, $validated['plan_key']);

            $unitLimitService->assertPlanFits($context['to']);

            if ($context['subscription'] !== null) {
                $swapService->applyCard(
                    $tenant,
                    $context['subscription'],
                    $context['to'],
                    $context['module_keys'],
                    $context['interval'],
                );
            } else {
                $swapService->applyManual($tenant, $context['to']);
            }
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (UnitLimitExceededException $e) {
            // MUST sit before catch(Throwable) below: UnitLimitExceededException
            // extends Exception, so Throwable would otherwise swallow it, log a
            // routine business block as an application error (defeating
            // ShouldntReport), and flatten the response to the generic message.
            return $e->render();
        } catch (Throwable $e) {
            Log::error('change billing plan failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'No se pudo cambiar el plan.'], 422);
        }

        $tenant = $tenant->fresh();

        return response()->json([
            'message' => 'Plan actualizado.',
            'data' => [
                'billing_package_id' => $tenant->billing_package_id,
                'subscription' => $this->presentMultiItemSubscription($tenant),
            ],
        ]);
    }

    /**
     * Preview the billing-plan change WITHOUT applying it: CARD → the proration
     * amount + proration_date to pin on apply; MANUAL → the new recurring price
     * (applies next cycle). Reuses PreferentialSwapService::previewCard/previewManual.
     *
     * plan-downgrade-guard (REQ-02/REQ-08): after building the payload, checks
     * (never asserts) whether the tenant's current active unit count fits the
     * TARGET plan via UnitLimitService::checkPlanFits(). When it does not fit,
     * appends `unit_limit_warning` to the payload — deliberately NOT a 422: the
     * frontend parses this response with a non-strict Zod schema that still
     * needs the proration data, so the preview must stay HTTP 200 either way.
     */
    public function previewBillingPlanChange(Request $request, PreferentialSwapService $swapService, UnitLimitService $unitLimitService): JsonResponse
    {
        $this->requireAbility('manage.subscription');

        $validated = $request->validate([
            'plan_key' => ['required', 'string'],
        ]);

        $tenant = Tenant::findOrFail(tenant()->getTenantKey());

        try {
            $context = $this->resolveBillingPlanChangeContext($tenant, $validated['plan_key']);

            $payload = $context['subscription'] !== null
                ? $swapService->previewCard($context['subscription'], $context['to'], $context['module_keys'], $context['interval'])
                : $swapService->previewManual($context['to'], $context['module_keys'], $context['interval']);

            $check = $unitLimitService->checkPlanFits($context['to']);

            if (! $check->allowed) {
                $payload['unit_limit_warning'] = [
                    'message' => $unitLimitService->buildPlanChangeMessage($check),
                    'plan_name' => $check->planName,
                    'unit_limit' => $check->unitLimit,
                    'active_units' => $check->activeUnits,
                    'suggested_plan_name' => $check->suggestedPlanName,
                ];
            }
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $payload]);
    }

    /**
     * Resolve the self-serve billing-plan-change context: the PUBLIC + active target
     * plan (with a priced matrix), the cluster's CONTRACTED (non-courtesy) active
     * module keys + interval, and the owner's active Stripe subscription (CARD) or
     * null (MANUAL). Guards that the target plan OFFERS every contracted module the
     * cluster currently has — otherwise a swap would silently drop a paid line.
     *
     * @return array{to: BillingPackage, module_keys: array<int, string>, interval: string, subscription: Subscription|null}
     *
     * @throws InvalidArgumentException
     */
    private function resolveBillingPlanChangeContext(Tenant $tenant, string $planKey): array
    {
        $to = BillingPackage::query()
            ->where('key', $planKey)
            ->where('is_active', true)
            ->where('is_public', true)
            ->whereHas('plan_prices')
            ->first();

        if ($to === null) {
            throw new InvalidArgumentException('El plan seleccionado no está disponible.');
        }

        // Contracted = active entitlements that are NOT free courtesy (super_admin_free):
        // those are the lines Stripe actually bills / the tenant pays for.
        $entitlements = TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->active()
            ->where('source', '!=', TenantEntitlement::SOURCE_SUPER_ADMIN_FREE)
            ->with('billable_module:id,key')
            ->get();

        $moduleKeys = $entitlements
            ->map(fn (TenantEntitlement $e): ?string => $e->billable_module?->key)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($moduleKeys === []) {
            throw new InvalidArgumentException('No tienes un plan contratado que se pueda cambiar.');
        }

        // Guard: the destination plan must OFFER every contracted module (active price row).
        $offered = PlanModulePrice::query()
            ->where('billing_package_id', $to->id)
            ->where('is_active', true)
            ->whereHas('billable_module', fn ($query) => $query->whereIn('key', $moduleKeys))
            ->with('billable_module:id,key')
            ->get()
            ->map(fn (PlanModulePrice $price): ?string => $price->billable_module?->key)
            ->filter()
            ->all();

        $missing = array_values(array_diff($moduleKeys, $offered));

        if ($missing !== []) {
            throw new InvalidArgumentException(
                'El plan seleccionado no cubre todo lo que tienes contratado.'
            );
        }

        $interval = $entitlements->pluck('interval')->filter()->first() ?: PlanModulePrice::INTERVAL_MONTH;

        $owner = $this->ownerResolver->resolve($tenant);
        $subscription = $owner?->subscription('default');
        $activeSubscription = $subscription && in_array($subscription->stripe_status, StripeSubscriptionStatuses::LIVE, true)
            ? $subscription
            : null;

        return [
            'to' => $to,
            'module_keys' => $moduleKeys,
            'interval' => $interval,
            'subscription' => $activeSubscription,
        ];
    }

    // -------------------------------------------------------------------------
    // Legal documents (§16.3.8)
    // -------------------------------------------------------------------------

    public function listLegalDocuments(): JsonResponse
    {
        $this->requireAbility('read.configuration');

        $documents = LegalDocument::query()
            ->orderBy('type')
            ->orderByDesc('version')
            ->get()
            ->map(fn (LegalDocument $doc): array => $this->presentLegalDocument($doc))
            ->values();

        return response()->json(['data' => $documents]);
    }

    public function uploadLegalDocument(Request $request): JsonResponse
    {
        $this->requireAbility('update.configuration');

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', LegalDocument::TYPES)],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_visible_to_residents' => ['sometimes', 'boolean'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        // Versioning under concurrency (§16.3.8): supersede + create run in ONE
        // transaction, locking the current row of this (tenant, type) so two
        // simultaneous uploads serialize instead of both reading the same
        // previousCurrent (which produced two is_current=true rows with the same
        // version, or — if create failed after the update — no current at all).
        // The partial unique index (one is_current per tenant+type) backstops the
        // no-previous-row race at the database level.
        try {
            $document = DB::transaction(function () use ($request, $validated): LegalDocument {
                $previousCurrent = LegalDocument::query()
                    ->where('type', $validated['type'])
                    ->where('is_current', true)
                    ->orderByDesc('version')
                    ->lockForUpdate()
                    ->first();

                $nextVersion = ($previousCurrent?->version ?? 0) + 1;

                if ($previousCurrent) {
                    $previousCurrent->update(['is_current' => false]);
                }

                return LegalDocument::create([
                    'type' => $validated['type'],
                    'title' => $validated['title'] ?? null,
                    'version' => $nextVersion,
                    'is_current' => true,
                    // Aviso de privacidad defaults to resident-visible (§16.3.8).
                    'is_visible_to_residents' => $request->has('is_visible_to_residents')
                        ? $request->boolean('is_visible_to_residents')
                        : $validated['type'] === LegalDocument::TYPE_AVISO_PRIVACIDAD,
                    'published_at' => now(),
                ]);
            });
        } catch (QueryException $e) {
            // Unique-index violation = a concurrent upload won the race.
            if ((string) $e->getCode() === '23505') {
                return response()->json([
                    'message' => 'Otro documento del mismo tipo se está publicando en este momento. Intenta de nuevo.',
                ], 409);
            }

            throw $e;
        }

        $document->addMedia($request->file('file'))->toMediaCollection('document');

        return response()->json(['data' => $this->presentLegalDocument($document->fresh())], 201);
    }

    /**
     * Resident-accessible: download the current resident-visible document of a type
     * (the aviso de privacidad in particular, §16.3.8). Gated only by auth.
     */
    public function showCurrentLegalDocument(string $type): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();

        if (! $user) {
            throw new AuthorizationException('Unauthorized action.');
        }

        if (! in_array($type, LegalDocument::TYPES, true)) {
            return response()->json(['message' => 'Tipo de documento inválido.'], 422);
        }

        $document = LegalDocument::query()
            ->where('type', $type)
            ->where('is_current', true)
            ->where('is_visible_to_residents', true)
            ->orderByDesc('version')
            ->first();

        if (! $document) {
            return response()->json(['message' => 'Documento no disponible.'], 404);
        }

        return response()->json(['data' => $this->presentLegalDocument($document)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentLegalDocument(LegalDocument $document): array
    {
        $url = $document->getFirstMediaUrl('document');

        return [
            'id' => $document->id,
            'type' => $document->type,
            'title' => $document->title,
            'version' => $document->version,
            'is_current' => $document->is_current,
            'is_visible_to_residents' => $document->is_visible_to_residents,
            'published_at' => $document->published_at,
            'file_url' => $url !== '' ? $url : null,
        ];
    }

    /**
     * Restore the default templates for the current tenant (used by the
     * "Restaurar default" action and as a programmatic reseed hook).
     */
    public function restoreDefaultTemplates(): JsonResponse
    {
        $this->requireAbility('update.configuration');

        (new NotificationTemplateSeeder)->run();

        return $this->getNotificationTemplates();
    }

    // -------------------------------------------------------------------------
    // Package settings (Setting table, package.* tenant-scoped key-value)
    // -------------------------------------------------------------------------

    /**
     * GET /config/package-settings
     *
     * Returns the 7 package policy slugs with flat keys (no `package.` prefix)
     * for the current tenant, mirroring the mold of getCollectionSettings().
     *
     * Permission: read.configuration (no separate package_config gate — per design §2).
     */
    public function getPackageSettings(): JsonResponse
    {
        $this->requireAbility('read.configuration');

        $slugs = [
            'package.auto_notify_on_arrival',
            'package.pickup_code_enabled',
            'package.require_pickup_code',
            'package.requires_pickup_signature',
            'package.requires_pickup_evidence',
            'package.reminder_after_days',
            'package.return_after_days',
            'package.liability_disclaimer',
        ];

        $settings = Setting::whereIn('slug', $slugs)->get()->keyBy('slug');

        $data = [];
        foreach ($slugs as $slug) {
            // Strip the 'package.' prefix so the response shape uses flat keys.
            $key = substr($slug, strlen('package.'));
            $data[$key] = $settings->get($slug)?->resolved_value;
        }

        return response()->json(['data' => $data]);
    }

    /**
     * PUT /config/package-settings
     *
     * Partial update — only keys present in the request are persisted.
     * Receives flat keys (e.g. `requires_pickup_signature`) and maps back to
     * `package.*` slugs before storing, sidestepping Laravel's dot-as-nested-
     * traversal behaviour (the dotted-slug gotcha documented in updateCollectionSettings).
     *
     * Cross-field rule: reminder_after_days MUST be less than return_after_days
     * when both are present (or when one is given and the other can be read from
     * the DB to derive the full pair).
     *
     * Permission: update.configuration (per design §2 — no separate package_config).
     */
    public function updatePackageSettings(Request $request): JsonResponse
    {
        $this->requireAbility('update.configuration');

        // Mapping: flat request key → Setting slug.
        $slugMap = [
            'auto_notify_on_arrival' => 'package.auto_notify_on_arrival',
            'pickup_code_enabled' => 'package.pickup_code_enabled',
            'require_pickup_code' => 'package.require_pickup_code',
            'requires_pickup_signature' => 'package.requires_pickup_signature',
            'requires_pickup_evidence' => 'package.requires_pickup_evidence',
            'reminder_after_days' => 'package.reminder_after_days',
            'return_after_days' => 'package.return_after_days',
            'liability_disclaimer' => 'package.liability_disclaimer',
        ];

        $rules = [
            'auto_notify_on_arrival' => ['sometimes', 'boolean'],
            'pickup_code_enabled' => ['sometimes', 'boolean'],
            'require_pickup_code' => ['sometimes', 'boolean'],
            'requires_pickup_signature' => ['sometimes', 'boolean'],
            'requires_pickup_evidence' => ['sometimes', 'boolean'],
            'reminder_after_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'return_after_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'liability_disclaimer' => ['sometimes', 'string', 'max:1000'],
        ];

        // Only validate keys that are actually present (partial update).
        $all = $request->only(array_keys($slugMap));
        $validator = validator($all, $rules);

        // Pre-read the persisted day values in a SINGLE batched query (mirroring
        // getPackageSettings) instead of issuing one uncached Setting::getValue()
        // SELECT per counterpart inside the closure below. Gated: only query when
        // at least one of the two day keys is present, so non-day updates pay nothing.
        $needsDbDays = array_key_exists('reminder_after_days', $all)
            || array_key_exists('return_after_days', $all);

        $dbDays = $needsDbDays
            ? Setting::whereIn('slug', ['package.reminder_after_days', 'package.return_after_days'])
                ->pluck('value', 'slug')
            : collect();

        // Cross-field constraint: reminder_after_days must be < return_after_days.
        // Evaluate when either or both are supplied — compare against the stored
        // value of whichever side is absent, so a single-key update cannot violate
        // the invariant relative to the persisted value.
        $validator->after(function (Validator $v) use ($all, $dbDays): void {
            if ($v->errors()->isNotEmpty()) {
                return; // Skip cross-field check when base rules already failed.
            }

            $reminderInput = array_key_exists('reminder_after_days', $all)
                ? (int) $all['reminder_after_days']
                : null;

            $returnInput = array_key_exists('return_after_days', $all)
                ? (int) $all['return_after_days']
                : null;

            // Cross-field only applies when at least one is being changed.
            if ($reminderInput === null && $returnInput === null) {
                return;
            }

            // Resolve the counterpart from the pre-read batch when only one side is given.
            $reminder = $reminderInput ?? (int) ($dbDays->get('package.reminder_after_days') ?? 1);
            $return = $returnInput ?? (int) ($dbDays->get('package.return_after_days') ?? 365);

            if ($reminder >= $return) {
                $v->errors()->add(
                    'reminder_after_days',
                    'Los días de recordatorio deben ser menores que los días de devolución.'
                );
            }
        });

        $validator->validate();

        $persisted = [];

        foreach ($all as $key => $value) {
            $slug = $slugMap[$key];

            if (is_bool($value)) {
                $type = Setting::TYPE_BOOLEAN;
            } elseif (is_int($value)) {
                $type = Setting::TYPE_INTEGER;
            } else {
                $type = Setting::TYPE_STRING;
            }

            Setting::putValue($slug, $value, $type);

            $persisted[$key] = $value;
        }

        return response()->json([
            'message' => 'Configuración de paquetería actualizada.',
            'data' => $persisted,
        ]);
    }

    // -------------------------------------------------------------------------
    // Complaint settings (Setting table, complaint.* tenant-scoped key-value)
    // -------------------------------------------------------------------------

    /**
     * Role keys selectable as complaint managers/reporters (complaints design
     * ADR-5/§10): the board roles plus administrator. The config lists WHICH
     * roles manage/report; Spatie decides WHETHER the endpoint is reachable.
     *
     * @var array<int, string>
     */
    private const COMPLAINT_ROLE_KEYS = [
        'president', 'treasurer', 'secretary', 'vocal', 'oversight_committee', 'administrator',
    ];

    /**
     * Manifest defaults for the 6 complaint settings (design §10), flat key →
     * default. MUST stay in sync with ComplaintService's fallback constants —
     * the service falls back to these same values when a row is absent.
     *
     * @return array<string, mixed>
     */
    private function complaintSettingDefaults(): array
    {
        return [
            'manager_role_keys' => ['president', 'treasurer', 'secretary', 'vocal', 'oversight_committee', 'administrator'],
            'reporter_role_keys' => ['president', 'treasurer', 'secretary', 'vocal', 'oversight_committee', 'administrator'],
            'auto_notify_managers_on_create' => true,
            'requires_evidence' => false,
            'resolution_days_target' => 15,
            'post_to_reports_channel' => true,
        ];
    }

    /**
     * GET /config/complaint-settings
     *
     * Returns the 6 complaint policy slugs with flat keys (no `complaint.`
     * prefix), mirroring the mold of getPackageSettings(). Absent rows fall
     * back to the manifest defaults (same fallbacks ComplaintService uses).
     *
     * Permission: read.configuration (unified — no per-module config gate).
     */
    public function getComplaintSettings(): JsonResponse
    {
        $this->requireAbility('read.configuration');

        $defaults = $this->complaintSettingDefaults();
        $slugs = array_map(fn (string $key): string => 'complaint.'.$key, array_keys($defaults));

        $settings = Setting::whereIn('slug', $slugs)->get()->keyBy('slug');

        $data = [];
        foreach ($defaults as $key => $default) {
            $data[$key] = $settings->get('complaint.'.$key)?->resolved_value ?? $default;
        }

        return response()->json(['data' => $data]);
    }

    /**
     * PUT /config/complaint-settings
     *
     * Partial update — only keys present in the request are persisted.
     * Receives flat keys and maps back to `complaint.*` slugs before storing,
     * sidestepping the dotted-slug gotcha (see updatePackageSettings). The
     * role-key arrays must be non-empty subsets of COMPLAINT_ROLE_KEYS: an
     * empty manager set would leave complaints unmanageable (the service
     * treats [] as "use defaults", so we reject it instead of persisting it).
     *
     * Permission: update.configuration.
     */
    public function updateComplaintSettings(Request $request): JsonResponse
    {
        $this->requireAbility('update.configuration');

        $slugMap = [
            'manager_role_keys' => 'complaint.manager_role_keys',
            'reporter_role_keys' => 'complaint.reporter_role_keys',
            'auto_notify_managers_on_create' => 'complaint.auto_notify_managers_on_create',
            'requires_evidence' => 'complaint.requires_evidence',
            'resolution_days_target' => 'complaint.resolution_days_target',
            'post_to_reports_channel' => 'complaint.post_to_reports_channel',
        ];

        $rules = [
            'manager_role_keys' => ['sometimes', 'array', 'min:1'],
            'manager_role_keys.*' => ['string', Rule::in(self::COMPLAINT_ROLE_KEYS)],
            'reporter_role_keys' => ['sometimes', 'array', 'min:1'],
            'reporter_role_keys.*' => ['string', Rule::in(self::COMPLAINT_ROLE_KEYS)],
            'auto_notify_managers_on_create' => ['sometimes', 'boolean'],
            'requires_evidence' => ['sometimes', 'boolean'],
            'resolution_days_target' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'post_to_reports_channel' => ['sometimes', 'boolean'],
        ];

        // Only validate keys that are actually present (partial update).
        $all = $request->only(array_keys($slugMap));
        validator($all, $rules)->validate();

        // The 'integer' rule accepts a numeric STRING ("15"), which is_int()
        // below would misclassify as TYPE_STRING — coerce after validation
        // (same fix as updateDelinquencySettings).
        if (array_key_exists('resolution_days_target', $all)) {
            $all['resolution_days_target'] = (int) $all['resolution_days_target'];
        }

        $persisted = [];

        foreach ($all as $key => $value) {
            $slug = $slugMap[$key];

            if (is_array($value)) {
                $type = Setting::TYPE_JSON;
                $stored = array_values($value);
            } elseif (is_bool($value)) {
                $type = Setting::TYPE_BOOLEAN;
                $stored = $value;
            } elseif (is_int($value)) {
                $type = Setting::TYPE_INTEGER;
                $stored = $value;
            } else {
                $type = Setting::TYPE_STRING;
                $stored = (string) $value;
            }

            Setting::putValue($slug, $stored, $type);

            $persisted[$key] = $value;
        }

        return response()->json([
            'message' => 'Configuración de incidencias actualizada.',
            'data' => $persisted,
        ]);
    }

    // -------------------------------------------------------------------------
    // Delinquency restriction settings (Setting table, delinquency.* tenant-scoped key-value)
    // -------------------------------------------------------------------------

    /**
     * GET /config/delinquency-settings
     *
     * Returns the 6 delinquency-restriction slugs with flat keys (no
     * `delinquency.` prefix) for the current tenant, mirroring the mold of
     * getPackageSettings() — NOT the dotted-key getCollectionSettings (documented
     * no-op bug with Laravel dot-as-nested-traversal).
     *
     * min_overdue_amount crosses the API boundary as a Money object (project
     * HARD rule — never a raw int); every other key returns its resolved raw
     * value.
     *
     * Permission: read.configuration (no separate delinquency_config gate).
     */
    public function getDelinquencySettings(): JsonResponse
    {
        $this->requireAbility('read.configuration');

        $slugMap = [
            'restrictions_enabled' => DelinquencySettings::RESTRICTIONS_ENABLED,
            'min_overdue_amount' => DelinquencySettings::MIN_OVERDUE_AMOUNT,
            'block_package_intake' => DelinquencySettings::BLOCK_PACKAGE_INTAKE,
            'block_amenities' => DelinquencySettings::BLOCK_AMENITIES,
            'block_visitor_access' => DelinquencySettings::BLOCK_VISITOR_ACCESS,
            'notice_text' => DelinquencySettings::NOTICE_TEXT,
        ];

        $defaults = DelinquencySettings::all();
        $settings = Setting::whereIn('slug', array_values($slugMap))->get()->keyBy('slug');

        $data = [];
        foreach ($slugMap as $key => $slug) {
            $resolved = $settings->get($slug)?->resolved_value ?? $defaults[$slug];

            $data[$key] = $key === 'min_overdue_amount'
                ? TenantMoney::make((int) $resolved)
                : $resolved;
        }

        return response()->json(['data' => $data]);
    }

    /**
     * PUT /config/delinquency-settings
     *
     * Partial update — only keys present in the request are persisted.
     * Receives flat keys and maps back to `delinquency.*` slugs before storing,
     * sidestepping the dotted-slug gotcha (see updatePackageSettings).
     * min_overdue_amount is accepted as an INTEGER in minor units (the
     * frontend converts pesos to minor units before sending).
     *
     * Permission: update.configuration.
     */
    public function updateDelinquencySettings(Request $request): JsonResponse
    {
        $this->requireAbility('update.configuration');

        $slugMap = [
            'restrictions_enabled' => DelinquencySettings::RESTRICTIONS_ENABLED,
            'min_overdue_amount' => DelinquencySettings::MIN_OVERDUE_AMOUNT,
            'block_package_intake' => DelinquencySettings::BLOCK_PACKAGE_INTAKE,
            'block_amenities' => DelinquencySettings::BLOCK_AMENITIES,
            'block_visitor_access' => DelinquencySettings::BLOCK_VISITOR_ACCESS,
            'notice_text' => DelinquencySettings::NOTICE_TEXT,
        ];

        $rules = [
            'restrictions_enabled' => ['sometimes', 'boolean'],
            'min_overdue_amount' => ['sometimes', 'integer', 'min:0'],
            'block_package_intake' => ['sometimes', 'boolean'],
            'block_amenities' => ['sometimes', 'boolean'],
            'block_visitor_access' => ['sometimes', 'boolean'],
            'notice_text' => ['sometimes', 'string', 'max:500'],
        ];

        // Only validate keys that are actually present (partial update).
        $all = $request->only(array_keys($slugMap));
        validator($all, $rules)->validate();

        // R-DELQ-8 / sdd-verify finding S-1: the 'integer' validation rule accepts a
        // numeric STRING ("500000"), which `is_bool()`/`is_int()` below would then
        // misclassify as TYPE_STRING. Coerce here, after validation guarantees the
        // value is genuinely integer-shaped, so the persisted row is always
        // Setting::TYPE_INTEGER regardless of whether the client sent an int or a
        // numeric string.
        if (array_key_exists('min_overdue_amount', $all)) {
            $all['min_overdue_amount'] = (int) $all['min_overdue_amount'];
        }

        $persisted = [];

        foreach ($all as $key => $value) {
            $slug = $slugMap[$key];

            if (is_bool($value)) {
                $type = Setting::TYPE_BOOLEAN;
            } elseif (is_int($value)) {
                $type = Setting::TYPE_INTEGER;
            } else {
                $type = Setting::TYPE_STRING;
            }

            Setting::putValue($slug, $value, $type);

            $persisted[$key] = $key === 'min_overdue_amount' ? TenantMoney::make((int) $value) : $value;
        }

        return response()->json([
            'message' => 'Configuración de restricciones por morosidad actualizada.',
            'data' => $persisted,
        ]);
    }

    // -------------------------------------------------------------------------
    // Notification config (per-event channels + board-role recipients)
    // -------------------------------------------------------------------------

    /**
     * GET /config/notifications
     *
     * Returns every notification event in display order, LEFT-merged with the
     * current tenant's per-event settings (channels + is_active + board_role_ids).
     * Also returns the active board-role catalog so the UI can render the
     * multi-select without a separate request.
     *
     * Events without a tenant setting row fall back to the central event's
     * default_channels and is_active=true.
     */
    public function getNotificationConfig(NotificationSettingService $service): JsonResponse
    {
        $this->requireAbility('read.configuration');

        return response()->json(['data' => $service->allForTenant()]);
    }

    /**
     * PUT /config/notifications
     *
     * Upserts per-event configuration for the current tenant.
     * Body: { "events": [ { "key", "is_active", "channel_email", "channel_push",
     *                        "channel_whatsapp", "board_role_ids": [] } ] }
     *
     * For audience=resident events, board_role_ids is silently ignored.
     */
    public function updateNotificationConfig(Request $request, NotificationSettingService $service): JsonResponse
    {
        $this->requireAbility('update.configuration');

        $request->validate([
            'events' => ['required', 'array'],
            'events.*.key' => ['required', 'string'],
            'events.*.is_active' => ['required', 'boolean'],
            'events.*.channel_email' => ['required', 'boolean'],
            'events.*.channel_push' => ['required', 'boolean'],
            'events.*.channel_whatsapp' => ['required', 'boolean'],
            'events.*.board_role_ids' => ['sometimes', 'array'],
            'events.*.board_role_ids.*' => ['uuid'],
        ]);

        $service->updateForTenant($request->input('events', []));

        return response()->json(['message' => 'Configuración de notificaciones actualizada.']);
    }
}
