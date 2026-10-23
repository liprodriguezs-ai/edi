<?php

namespace Modules\Instrument\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Central semaphore color catalog (D2/D3), NOT versioned. Rows are seeded
 * once by CatalogSeeder::seedResultColors() and are read-only for tenant
 * roles. Screening rules and item options branch on these fixed UUIDs.
 */
#[ObservedBy([GlobalObserver::class])]
class ResultColor extends CentralCatalogModel
{
    protected $table = 'result_color';

    public const GREEN = 'ed200001-0000-4000-8000-000000000001';

    public const YELLOW = 'ed200001-0000-4000-8000-000000000002';

    public const RED = 'ed200001-0000-4000-8000-000000000003';

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
