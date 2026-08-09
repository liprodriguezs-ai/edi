<?php

namespace Modules\Collections\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class PaymentStatus extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'payment_status';

    public const CONFIRMED = '31095c7b-989c-423f-bd1e-bad68f65fbc7';

    public const PENDING_VALIDATION = 'f122bfe6-1c08-45fc-ac16-399df1b9d97e';

    public const CANCELLED = '12947ed0-5602-45dc-a6b7-5edfa7c07584';

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
