<?php

namespace Modules\SubscriptionPlan\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Central ledger row for a tenant's subscription payment. Manual payments
 * (transfer/cash) are recorded by a super-admin and extend the tenant's
 * entitlement period; stripe payments are mirrored from invoices. No tenant_id
 * scope of its own — like tenant_entitlement / billing_package it lives on the
 * central connection and is keyed by an explicit tenant_id column.
 *
 * `method` is stored as a plain string column validated by class constants
 * (NEVER native PHP enums — laravel-backend-architecture skill rule 14, same convention as
 * Tenant.payment_method / BillingPackage.type).
 */
#[ObservedBy([GlobalObserver::class])]
class SubscriptionPayment extends BaseModel
{
    use CentralConnection;

    protected $table = 'subscription_payment';

    public const METHOD_CARD = 'card';

    public const METHOD_TRANSFER = 'transfer';

    public const METHOD_CASH = 'cash';

    public const METHODS = [
        self::METHOD_CARD,
        self::METHOD_TRANSFER,
        self::METHOD_CASH,
    ];

    /**
     * Methods valid for a MANUAL (super-admin) recording. `card` is settled by
     * Stripe — it is never an out-of-band manual payment — so it is excluded
     * here even though it is a valid ledger `method` for mirrored stripe rows.
     */
    public const MANUAL_METHODS = [
        self::METHOD_TRANSFER,
        self::METHOD_CASH,
    ];

    protected $fillable = [
        'tenant_id',
        'billable_module_id',
        'billing_package_id',
        'tenant_entitlement_id',
        'amount',
        'currency',
        'method',
        'paid_at',
        'period_start',
        'period_end',
        'recorded_by_user_id',
        'stripe_invoice_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'datetime',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function billable_module(): BelongsTo
    {
        return $this->belongsTo(BillableModule::class);
    }

    public function billing_package(): BelongsTo
    {
        return $this->belongsTo(BillingPackage::class);
    }

    public function tenant_entitlement(): BelongsTo
    {
        return $this->belongsTo(TenantEntitlement::class);
    }

    public function recorded_by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
