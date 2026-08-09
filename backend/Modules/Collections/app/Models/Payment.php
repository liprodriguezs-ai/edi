<?php

namespace Modules\Collections\Models;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Housing\Models\Unit;
use Modules\Treasury\Models\BankAccount;
use QCH\Core\Casts\TenantMoneyCast;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class Payment extends BaseModel implements HasMedia
{
    use BelongsToTenant;
    use HasFactory;
    use InteractsWithMedia;

    protected $table = 'payment';

    protected $fillable = [
        'unit_id',
        'payer_user_id',
        'paid_at',
        'total_amount',
        'payment_method_id',
        'bank_account_id',
        'reference',
        'receipt_number',
        'physical_receipt_number',
        'payment_status_id',
        'cancellation_reason',
        'notes',
        'earmark_type',
        'earmark_id',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => TenantMoneyCast::class,
            'paid_at' => 'date',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /**
     * payer_user_id points to the central User table.
     * Must bypass UserTenantScope since payment is tenant-scoped but user is central.
     */
    public function payer_user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_user_id')
            ->withoutGlobalScope(UserTenantScope::class);
    }

    public function payment_method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function bank_account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function payment_status(): BelongsTo
    {
        return $this->belongsTo(PaymentStatus::class, 'payment_status_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(PaymentApplication::class, 'payment_id');
    }

    /**
     * Register media collections for payments.
     * - receipt: single comprobante de pago (PDF or image), uploaded by the payer.
     * - receipt_pdf: the system-generated receipt PDF (§8.5.3 / Flujo B).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('receipt')
            ->acceptsMimeTypes([
                'application/pdf',
                'image/jpeg',
                'image/png',
                'image/webp',
            ])
            ->singleFile();

        $this->addMediaCollection('receipt_pdf')
            ->acceptsMimeTypes(['application/pdf'])
            ->singleFile();
    }

    public function reportColumns(): array
    {
        return [
            'Unidad' => function ($result) {
                return $result->unit?->full_identifier;
            },
            'Pagador' => function ($result) {
                return $result->payer_user ? trim($result->payer_user->name.' '.$result->payer_user->last_name.' '.($result->payer_user->second_last_name ?? '')) : '';
            },
            'F. de pago' => function ($result) {
                return $result->paid_at ? $result->paid_at->format('d/m/Y') : '';
            },
            'Monto total' => function ($result) {
                return $result->total_amount ? (float) $result->total_amount->formatByDecimal() : 0;
            },
            'Método de pago' => function ($result) {
                return $result->payment_method?->name;
            },
            'Cuenta bancaria' => function ($result) {
                return $result->bank_account?->name;
            },
            'Referencia' => function ($result) {
                return $result->reference;
            },
            'No. de recibo' => function ($result) {
                return $result->receipt_number;
            },
            'Recibo físico' => function ($result) {
                return $result->physical_receipt_number;
            },
            'Estado' => function ($result) {
                return $result->payment_status?->name;
            },
            'Notas' => function ($result) {
                return $result->notes;
            },
            'F. creación' => function ($result) {
                return $result->created_at ? $result->created_at->format('d/m/Y') : '';
            },
        ];
    }
}
