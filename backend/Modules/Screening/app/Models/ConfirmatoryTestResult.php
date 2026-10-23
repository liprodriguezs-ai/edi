<?php

namespace Modules\Screening\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Central catalog of confirmatory test outcomes (design D1/D20, REQ-SCR-014).
 * Fixed UUIDs, seeded once by CatalogSeeder.
 */
#[ObservedBy([GlobalObserver::class])]
class ConfirmatoryTestResult extends CentralCatalogModel
{
    protected $table = 'confirmatory_test_result';

    public const DELAY_CONFIRMED = 'ed300004-0000-4000-8000-000000000001';

    public const DELAY_RULED_OUT = 'ed300004-0000-4000-8000-000000000002';

    public const INCONCLUSIVE = 'ed300004-0000-4000-8000-000000000003';

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
