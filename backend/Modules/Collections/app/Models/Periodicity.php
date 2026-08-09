<?php

namespace Modules\Collections\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class Periodicity extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'periodicity';

    public const MONTHLY = '97b53d7a-48bf-47e5-b29b-865d17913253';

    public const BIMONTHLY = '582abd27-ded4-4e95-8ec3-9bdefed30904';

    public const YEARLY = 'a3605528-1321-41aa-ba8f-2a1d285dbc11';

    public const EVENTUAL = '2996f547-09d8-42e2-a448-69f4c2c2a477';

    /** @var string Fixed UUID — must stay identical across environments and the CatalogSeeder. */
    public const WEEKLY = 'b1d3f4a2-5c6e-4f8a-9b0c-1d2e3f4a5b6c';

    /** @var string Fixed UUID — must stay identical across environments and the CatalogSeeder. */
    public const BIWEEKLY = 'c2e4a5b3-6d7f-4a9b-8c1d-2e3f4a5b6c7d';

    /** @var string Fixed UUID — must stay identical across environments and the CatalogSeeder. QA-only cadence. */
    public const EVERY_TWO_DAYS = '39a1ff2c-c536-4680-a854-a7f443033b51';

    /**
     * Compressed QA cadences. Currently NOT gated anywhere: the rows are seeded and
     * offered in every environment, production included, so the 2-day cycle can be
     * exercised against real data. Kept as the source list for re-gating later.
     *
     * @var array<int, string>
     */
    public const QA_ONLY = [self::EVERY_TWO_DAYS];

    protected $fillable = [
        'id',
        'name',
        'order',
        'is_active',
        'is_automatic',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_automatic' => 'boolean',
            'order' => 'integer',
        ];
    }
}
