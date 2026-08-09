<?php

namespace Modules\Collections\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use QCH\Core\Casts\TenantMoneyCast;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class PaymentApplication extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'payment_application';

    /**
     * Funding source: the application was paid with the payment's own cash.
     */
    public const SOURCE_CASH = 'cash';

    /**
     * Funding source: the application consumed pre-existing credit balance.
     */
    public const SOURCE_CREDIT = 'credit';

    protected $fillable = [
        'payment_id',
        'charge_id',
        'applied_amount',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'applied_amount' => TenantMoneyCast::class,
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class, 'charge_id');
    }
}
