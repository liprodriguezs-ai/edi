<?php

namespace Modules\Board\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class BoardRole extends CentralCatalogModel
{
    use HasFactory;

    // Key constants for stable reference in code (the 'key' column value per tenant)
    const PRESIDENT = 'e4134bb8-c7ac-445d-887b-c690c6cb748e';

    const TREASURER = 'e300a1ca-d9aa-4126-9cba-843b416dc170';

    const SECRETARY = '75e8277c-ab17-4150-99a5-4ae6d0241860';

    const VOCAL = '84bcd114-2d85-4edc-98f2-65cc204e893c';

    const OVERSIGHT_COMMITTEE = '8b9512fe-f680-455a-898b-83e876fe24d1';

    protected $table = 'board_role';

    protected $fillable = [
        'id',
        'name',
        'spatie_role_slug',
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

    public function members(): HasMany
    {
        return $this->hasMany(BoardMember::class, 'board_role_id');
    }
}
