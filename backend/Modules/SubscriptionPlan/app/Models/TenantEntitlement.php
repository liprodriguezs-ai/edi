<?php

namespace Modules\SubscriptionPlan\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Central projection of a tenant's billing entitlement (which billing package
 * the organization is allowed to use and why). No tenant_id scope of its own — like
 * billing_package / subscription_plan it lives on the central connection and is
 * keyed by an explicit tenant_id column.
 *
 * `source` is stored as a plain string column validated by class constants
 * (NEVER native PHP enums — laravel-backend-architecture skill rule 14, same convention as
 * BillingPackage.type / SubscriptionPlan.interval).
 *
 * The EntitlementService (Phase 2 / A2) reads the ACTIVE rows of a tenant to
 * recompute system_tenant / module_tenant. This model is additive in A1.
 */
#[ObservedBy([GlobalObserver::class])]
class TenantEntitlement extends BaseModel
{
    use CentralConnection;

    protected $table = 'tenant_entitlement';

    public const SOURCE_STRIPE = 'stripe';

    // Free courtesy access granted by a super-admin: $0, perpetual (expires_at
    // null), never billed. NOT the same as a paid manual payment.
    public const SOURCE_SUPER_ADMIN_FREE = 'super_admin_free';

    // PAID out-of-band (bank transfer / cash) recorded by a super-admin via
    // SubscriptionPaymentService::recordManual. Real money changed hands, so the
    // entitlement carries an expiry and is extended one cycle per payment — it is
    // NOT a courtesy and must never surface as "Cortesía gratuita".
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_TRIAL = 'trial';

    public const SOURCE_LEGACY_PLAN = 'legacy_plan';

    public const SOURCES = [
        self::SOURCE_STRIPE,
        self::SOURCE_SUPER_ADMIN_FREE,
        self::SOURCE_MANUAL,
        self::SOURCE_TRIAL,
        self::SOURCE_LEGACY_PLAN,
    ];

    protected $fillable = [
        'tenant_id',
        'billable_module_id',
        'billing_package_id',
        'source',
        'interval',
        'quantity',
        'stripe_subscription_id',
        'stripe_item_id',
        'expires_at',
        'trial_expiry_notified_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'expires_at' => 'datetime',
            'trial_expiry_notified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The billable module this entitlement grants (the unit of entitlement).
     */
    public function billable_module(): BelongsTo
    {
        return $this->belongsTo(BillableModule::class);
    }

    /**
     * The PLAN (billing_package: basico..premium) this module was granted under.
     */
    public function billing_package(): BelongsTo
    {
        return $this->belongsTo(BillingPackage::class);
    }

    /**
     * Alias of billing_package() — the plan, read side-by-side with the module.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(BillingPackage::class, 'billing_package_id');
    }

    /**
     * Active entitlements: no expiry, or an expiry still in the future.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $inner): void {
            $inner->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }
}
