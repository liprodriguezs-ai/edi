<?php

namespace Modules\Collections\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

/**
 * SurchargeMode — CENTRAL fixed-UUID catalog (no tenant_id) defining how a late-fee
 * surcharge value is interpreted. Mirrors AmountMode.
 *
 *   - PERCENT: surcharge_value is BASIS POINTS (500 = 5.00%) of the overdue base.
 *   - FIXED: surcharge_value is a flat amount in minor units (cents).
 *
 * The accrual engine branches on these UUIDs, so they are stable constants seeded
 * once by CatalogSeeder and read-only for tenant roles.
 */
#[ObservedBy([GlobalObserver::class])]
class SurchargeMode extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'surcharge_mode';

    public const PERCENT = 'c595cf11-8209-4e34-90e5-b7957478ee39';

    public const FIXED = '6bc44bb8-cde0-4ce8-8c6f-5d10ddf9aa4c';

    protected $fillable = [
        'id',
        'name',
        'order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'order' => 'integer',
        ];
    }
}
