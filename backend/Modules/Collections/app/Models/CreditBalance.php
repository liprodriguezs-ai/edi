<?php

namespace Modules\Collections\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Collections\Observers\StatementTotalsCacheObserver;
use Modules\Housing\Models\Unit;
use QCH\Core\Casts\TenantMoneyCast;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([StatementTotalsCacheObserver::class, GlobalObserver::class])]
class CreditBalance extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'credit_balance';

    protected $fillable = [
        'unit_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => TenantMoneyCast::class,
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }
}
