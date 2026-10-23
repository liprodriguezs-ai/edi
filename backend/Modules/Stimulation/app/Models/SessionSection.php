<?php

namespace Modules\Stimulation\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Central catalog of the 4 official stimulation session sections (D4). Rows
 * are seeded once by CatalogSeeder::seedSessionSections() and are read-only
 * for tenant roles. No FK consumer in v1 (design §8 Q3) — kept as the
 * official vocabulary for rendering/export labels.
 */
#[ObservedBy([GlobalObserver::class])]
class SessionSection extends CentralCatalogModel
{
    protected $table = 'session_section';

    public const INTEGRATION = 'ed400001-0000-4000-8000-000000000001';

    public const PREPARATION = 'ed400001-0000-4000-8000-000000000002';

    public const CORE = 'ed400001-0000-4000-8000-000000000003';

    public const RELAXATION = 'ed400001-0000-4000-8000-000000000004';

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
