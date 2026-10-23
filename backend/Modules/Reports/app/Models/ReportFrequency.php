<?php

namespace Modules\Reports\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Central cadence catalog for scheduled reports (design D15). The runner
 * BRANCHES on these keys to decide what is due tonight, so they carry fixed
 * UUIDs and are seeded once by CatalogSeeder — never tenant-editable.
 */
#[ObservedBy([GlobalObserver::class])]
class ReportFrequency extends CentralCatalogModel
{
    protected $table = 'report_frequency';

    public const DAILY = 'ed400001-0000-4000-8000-000000000001';

    public const WEEKLY = 'ed400001-0000-4000-8000-000000000002';

    public const MONTHLY = 'ed400001-0000-4000-8000-000000000003';

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
