<?php

namespace Modules\Access\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class BlacklistType extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'blacklist_type';

    public const VISITOR = '0a285be8-de49-4077-9655-a3fbb16befb7';

    public const VEHICLE = '875aefe3-6904-421d-95f3-488c58ec0429';

    protected $fillable = [
        'id',
        'name',
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
}
