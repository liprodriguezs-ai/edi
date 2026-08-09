<?php

namespace Modules\Access\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class QrType extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'qr_type';

    public const SINGLE_ENTRY = '6a0297c4-a5b6-4f38-b4bb-4407ac4555d4';

    public const TIME_BASED = '4e08d416-3344-445d-be61-7161800f8e94';

    public const EVENT = '994ae3cd-b71c-42ce-870a-232d5346facd';

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
