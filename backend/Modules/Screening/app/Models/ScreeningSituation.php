<?php

namespace Modules\Screening\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Central catalog of situation states (design D1/D12, REQ-SCR-012), mirrored
 * 1:1 from the engine's SituationKey constants. `sis_code` is the legacy
 * official-export code (1-6); `unchanged` and `worsened` are distinct rows
 * sharing sis_code 6.
 */
#[ObservedBy([GlobalObserver::class])]
class ScreeningSituation extends CentralCatalogModel
{
    protected $table = 'screening_situation';

    public const FIRST_TIME_GREEN = 'ed300002-0000-4000-8000-000000000001';

    public const FIRST_TIME_YELLOW = 'ed300002-0000-4000-8000-000000000002';

    public const FIRST_TIME_RED = 'ed300002-0000-4000-8000-000000000003';

    public const RECOVERED_FROM_YELLOW = 'ed300002-0000-4000-8000-000000000004';

    public const RECOVERED_FROM_RED = 'ed300002-0000-4000-8000-000000000005';

    public const UNCHANGED = 'ed300002-0000-4000-8000-000000000006';

    public const WORSENED = 'ed300002-0000-4000-8000-000000000007';

    protected $fillable = [
        'id',
        'key',
        'name',
        'sis_code',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sis_code' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
