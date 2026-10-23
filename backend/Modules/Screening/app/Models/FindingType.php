<?php

namespace Modules\Screening\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Central catalog mirroring the engine's closed ScoringFinding vocabulary
 * (design D1, REQ-SCR-001/007). Fixed UUIDs, seeded once by CatalogSeeder.
 */
#[ObservedBy([GlobalObserver::class])]
class FindingType extends CentralCatalogModel
{
    protected $table = 'finding_type';

    public const AGE_GROUP_SUBSTITUTED_BY_CORRECTED_AGE = 'ed300003-0000-4000-8000-000000000001';

    public const UNKNOWN_PERINATAL_INPUT = 'ed300003-0000-4000-8000-000000000002';

    public const AUTO_ANSWER_OVERRODE_MANUAL = 'ed300003-0000-4000-8000-000000000003';

    public const UNANSWERED_ITEM = 'ed300003-0000-4000-8000-000000000004';

    public const MISSING_PERCENTILE_REFERENCE = 'ed300003-0000-4000-8000-000000000005';

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
