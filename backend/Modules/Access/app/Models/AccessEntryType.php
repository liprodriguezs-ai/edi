<?php

namespace Modules\Access\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class AccessEntryType extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'access_entry_type';

    public const QR = '0662764e-fe7d-40e5-9131-19d546b834b3';

    public const MANUAL = 'ef31e5a4-5012-420c-8aef-e9a25d24b49d';

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
