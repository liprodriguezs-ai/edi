<?php

namespace Modules\FollowUp\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Central FSM catalog for the follow-up lifecycle (design D6/D7). Fixed
 * UUIDs, seeded once by CatalogSeeder, read-only for tenant roles.
 */
#[ObservedBy([GlobalObserver::class])]
class FollowUpStatus extends CentralCatalogModel
{
    protected $table = 'follow_up_status';

    public const PENDING = 'ed500002-0000-4000-8000-000000000001';

    public const IN_PROGRESS = 'ed500002-0000-4000-8000-000000000002';

    public const COMPLETED = 'ed500002-0000-4000-8000-000000000003';

    public const OVERDUE = 'ed500002-0000-4000-8000-000000000004';

    public const CANCELLED = 'ed500002-0000-4000-8000-000000000005';

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
