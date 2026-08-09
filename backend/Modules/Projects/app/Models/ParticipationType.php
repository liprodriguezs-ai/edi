<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class ParticipationType extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'participation_type';

    public const MANDATORY = 'c4814f21-ba4f-49f3-9b5d-2d20dea38296';

    public const VOLUNTARY = '8ad8e08a-031c-4a6c-b7f3-bd548fa743e2';

    protected $fillable = [
        'id',
        'name',
        'order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'order' => 'integer',
        ];
    }
}
