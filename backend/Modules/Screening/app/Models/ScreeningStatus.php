<?php

namespace Modules\Screening\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Central FSM catalog for the screening lifecycle (design D1/D15). Fixed
 * UUIDs, seeded once by CatalogSeeder, read-only for tenant roles.
 */
#[ObservedBy([GlobalObserver::class])]
class ScreeningStatus extends CentralCatalogModel
{
    protected $table = 'screening_status';

    public const DRAFT = 'ed300001-0000-4000-8000-000000000001';

    public const SCORED = 'ed300001-0000-4000-8000-000000000002';

    public const COMPLETED = 'ed300001-0000-4000-8000-000000000003';

    public const INVALIDATED = 'ed300001-0000-4000-8000-000000000004';

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
