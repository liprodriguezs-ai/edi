<?php

namespace Modules\Appointment\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Central recall interval by age band, seeded by CatalogSeeder from
 * NOM-031-SSA2-1999 (8.4.2 / 8.4.3).
 */
#[ObservedBy([GlobalObserver::class])]
class WellChildInterval extends CentralCatalogModel
{
    protected $table = 'well_child_interval';

    public const UNDER_ONE_YEAR = 'ed600003-0000-4000-8000-000000000001';

    public const ONE_TO_FOUR_YEARS = 'ed600003-0000-4000-8000-000000000002';

    public const FIVE_YEARS_AND_OVER = 'ed600003-0000-4000-8000-000000000003';

    protected $fillable = [
        'id',
        'min_age_days',
        'max_age_days',
        'interval_days',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'min_age_days' => 'integer',
            'max_age_days' => 'integer',
            'interval_days' => 'integer',
        ];
    }
}
