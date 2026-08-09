<?php

namespace Modules\Board\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class BoardPeriodStatus extends CentralCatalogModel
{
    use HasFactory;

    // Key constants for stable reference in code
    const CURRENT = '43dfabcf-0245-4370-a742-50f3f744b040';

    const CLOSED = '8b133be2-c5d4-45a6-a5b9-6a621b70af80';

    protected $table = 'board_period_status';

    protected $fillable = [
        'id',
        'name',
        'color',
        'order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function periods(): HasMany
    {
        return $this->hasMany(BoardPeriod::class, 'board_period_status_id');
    }
}
