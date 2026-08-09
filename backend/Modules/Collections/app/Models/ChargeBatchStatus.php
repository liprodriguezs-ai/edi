<?php

namespace Modules\Collections\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class ChargeBatchStatus extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'charge_batch_status';

    public const DRAFT = 'fdfc6680-e268-4d0e-9a86-cfaa000771e7';

    public const ACTIVE = '69d2ec6a-f18e-4235-be66-de0f8b929e9d';

    public const CANCELLED = '0c4e48a8-3ff4-4a8e-af81-d09c199003f7';

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
            'is_active' => 'boolean',
            'order' => 'integer',
        ];
    }
}
