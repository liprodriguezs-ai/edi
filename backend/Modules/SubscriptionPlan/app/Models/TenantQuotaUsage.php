<?php

declare(strict_types=1);

namespace Modules\SubscriptionPlan\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Per-tenant lifetime consumption counter for the ACCUMULATING quota
 * resources (`amenity_reservation`, `visitor`). `unit` has no row here — it
 * is an inventory resource counted live (see UnitLimitService::activeUnitCount()).
 *
 * Rows are read with lockForUpdate() and incremented inside the caller's
 * transaction — never read without the lock (QuotaService::consume()).
 */
#[ObservedBy([GlobalObserver::class])]
class TenantQuotaUsage extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'tenant_quota_usage';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'resource_key',
        'used_count',
    ];

    protected function casts(): array
    {
        return [
            'used_count' => 'integer',
        ];
    }
}
