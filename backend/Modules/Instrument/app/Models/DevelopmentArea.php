<?php

namespace Modules\Instrument\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Central development-area catalog (D2/D3), NOT versioned. Rows are seeded
 * once by CatalogSeeder::seedDevelopmentAreas() and are read-only for tenant
 * roles. scoring_kind and is_development_axis are behavior selectors the
 * verify service and (later) the scoring engine branch on.
 */
#[ObservedBy([GlobalObserver::class])]
class DevelopmentArea extends CentralCatalogModel
{
    protected $table = 'development_area';

    public const MG = 'ed200002-0000-4000-8000-000000000001';

    public const MF = 'ed200002-0000-4000-8000-000000000002';

    public const LE = 'ed200002-0000-4000-8000-000000000003';

    public const SO = 'ed200002-0000-4000-8000-000000000004';

    public const CO = 'ed200002-0000-4000-8000-000000000005';

    public const EN = 'ed200002-0000-4000-8000-000000000006';

    public const ALA = 'ed200002-0000-4000-8000-000000000007';

    public const ALE = 'ed200002-0000-4000-8000-000000000008';

    public const SCORING_KIND_SEMAPHORE = 'semaphore';

    public const SCORING_KIND_COUNTER = 'counter';

    protected $fillable = [
        'id',
        'code',
        'name',
        'scoring_kind',
        'is_development_axis',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_development_axis' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
