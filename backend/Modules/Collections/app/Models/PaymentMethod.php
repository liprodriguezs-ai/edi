<?php

namespace Modules\Collections\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class PaymentMethod extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'payment_method';

    public const CASH = 'cc956c54-f78f-454c-83da-60cf9641139b';

    public const TRANSFER = 'e35bbfd8-4c81-4ff2-ba38-ba4966447df9';

    public const DEPOSIT = '016887ae-9986-4553-94e2-ba6b253e2399';

    public const CHECK = 'e72f8440-fd1e-43be-a323-07980d54a677';

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
