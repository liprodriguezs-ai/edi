<?php

namespace Modules\Collections\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\AmenityReservations\Models\AmenityReservation;
use Modules\Collections\Observers\StatementTotalsCacheObserver;
use Modules\Housing\Models\Unit;
use QCH\Core\Casts\TenantMoneyCast;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([StatementTotalsCacheObserver::class, GlobalObserver::class])]
class Charge extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'charge';

    protected $fillable = [
        'charge_batch_id',
        'charge_concept_id',
        'description',
        'charge_policy_id',
        'unit_id',
        'expected_amount',
        'paid_amount',
        'discount_amount',
        'discount_reason',
        'discounted_by_user_id',
        'surcharge_amount',
        'surcharge_recomputed_at',
        'tax_amount',
        'issued_at',
        'due_date',
        'grace_days',
        'due_soon_days',
        'charge_status_id',
        'cancellation_reason',
        'is_cancelled',
        'community_project_id',
        'amenity_reservation_id',
    ];

    protected function casts(): array
    {
        return [
            'expected_amount' => TenantMoneyCast::class,
            'paid_amount' => TenantMoneyCast::class,
            'discount_amount' => TenantMoneyCast::class,
            'surcharge_amount' => TenantMoneyCast::class,
            'tax_amount' => TenantMoneyCast::class,
            'surcharge_recomputed_at' => 'date',
            'issued_at' => 'date',
            'due_date' => 'date',
            'grace_days' => 'integer',
            'due_soon_days' => 'integer',
            'is_cancelled' => 'boolean',
        ];
    }

    /**
     * SQL expression for a charge's outstanding balance in MINOR UNITS.
     *
     * Single shared source of the "what is owed" formula for SQL aggregations
     * (delinquency dashboard/export, aging report). MUST stay in parity with
     * ChargeStatusService::effectiveExpected(), the PHP source of truth:
     *   outstanding = max(0, max(0, expected − discount) + surcharge − paid)
     * The principal floors after the discount, then the accrued late-fee
     * surcharge is added BEFORE subtracting payments — dropping the surcharge
     * understates overdue debt.
     */
    public static function outstandingSqlExpression(): string
    {
        return 'GREATEST(0, GREATEST(0, expected_amount - COALESCE(discount_amount, 0))'
            .' + COALESCE(surcharge_amount, 0) - paid_amount)';
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ChargeBatch::class, 'charge_batch_id');
    }

    public function concept(): BelongsTo
    {
        return $this->belongsTo(ChargeConcept::class, 'charge_concept_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ChargePolicy::class, 'charge_policy_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function charge_status(): BelongsTo
    {
        return $this->belongsTo(ChargeStatus::class, 'charge_status_id');
    }

    public function payment_applications(): HasMany
    {
        return $this->hasMany(PaymentApplication::class, 'charge_id');
    }

    /**
     * S8: set when this charge was created by a confirmed amenity-reservation
     * earmark payment (AmenityChargeService::createChargeForReservation).
     */
    public function amenity_reservation(): BelongsTo
    {
        return $this->belongsTo(AmenityReservation::class, 'amenity_reservation_id');
    }

    public function reportColumns(): array
    {
        return [
            'Unidad' => function ($result) {
                return $result->unit?->full_identifier;
            },
            'Concepto' => function ($result) {
                return $result->concept?->name;
            },
            'Cobro' => function ($result) {
                return $result->batch?->title;
            },
            'Monto esperado' => function ($result) {
                return $result->expected_amount ? (float) $result->expected_amount->formatByDecimal() : 0;
            },
            'Monto pagado' => function ($result) {
                return $result->paid_amount ? (float) $result->paid_amount->formatByDecimal() : 0;
            },
            'Descuento' => function ($result) {
                return $result->discount_amount ? (float) $result->discount_amount->formatByDecimal() : 0;
            },
            'Recargo' => function ($result) {
                return $result->surcharge_amount ? (float) $result->surcharge_amount->formatByDecimal() : 0;
            },
            'Impuesto' => function ($result) {
                return $result->tax_amount ? (float) $result->tax_amount->formatByDecimal() : 0;
            },
            'F. emisión' => function ($result) {
                return $result->issued_at ? $result->issued_at->format('d/m/Y') : '';
            },
            'F. vencimiento' => function ($result) {
                return $result->due_date ? $result->due_date->format('d/m/Y') : '';
            },
            'Estado' => function ($result) {
                return $result->charge_status?->name;
            },
            'Cancelado' => function ($result) {
                return $result->is_cancelled ? 'Sí' : 'No';
            },
            'F. creación' => function ($result) {
                return $result->created_at ? $result->created_at->format('d/m/Y') : '';
            },
        ];
    }
}
