<?php

namespace Modules\FollowUp\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Central catalog of follow-up conducts (design D6). Fixed UUIDs, seeded
 * once by CatalogSeeder, read-only for tenant roles.
 */
#[ObservedBy([GlobalObserver::class])]
class FollowUpType extends CentralCatalogModel
{
    protected $table = 'follow_up_type';

    public const REASSESS_3_MONTHS = 'ed500001-0000-4000-8000-000000000001';

    public const SPECIALIST_REFERRAL = 'ed500001-0000-4000-8000-000000000002';

    public const CONFIRMATORY_TEST = 'ed500001-0000-4000-8000-000000000003';

    protected $fillable = [
        'id',
        'key',
        'name',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
