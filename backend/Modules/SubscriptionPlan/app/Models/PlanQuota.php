<?php

declare(strict_types=1);

namespace Modules\SubscriptionPlan\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Central catalog row: how much of a resource a plan (or every plan, when
 * `billing_package_id` is null) may consume under a subscription scope.
 * `-1` (see UNLIMITED) is the shipped-OFF value — see PlanQuotaSeeder.
 *
 * Central (no `tenant_id`, no BelongsToTenant): tenant roles never write
 * this table. Resolution lives in QuotaService::resolveQuota() — the SINGLE
 * translation point where `-1`/absent becomes null-unlimited (D6). Nothing
 * outside that method should read `limit_value` directly.
 */
#[ObservedBy([GlobalObserver::class])]
class PlanQuota extends BaseModel
{
    protected $table = 'plan_quota';

    public const SCOPE_TRIAL = 'trial';

    public const SCOPE_ACTIVE = 'active';

    public const SCOPES = [self::SCOPE_TRIAL, self::SCOPE_ACTIVE];

    public const RESOURCE_UNIT = 'unit';

    public const RESOURCE_AMENITY_RESERVATION = 'amenity_reservation';

    public const RESOURCE_VISITOR = 'visitor';

    public const RESOURCES = [self::RESOURCE_UNIT, self::RESOURCE_AMENITY_RESERVATION, self::RESOURCE_VISITOR];

    /** `limit_value` sentinel for "no cap" — used ONLY by the seeder and QuotaService::resolveQuota() (D6). */
    public const UNLIMITED = -1;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'billing_package_id',
        'scope',
        'resource_key',
        'limit_value',
    ];

    protected function casts(): array
    {
        return [
            'limit_value' => 'integer',
        ];
    }

    public function billing_package(): BelongsTo
    {
        return $this->belongsTo(BillingPackage::class);
    }
}
