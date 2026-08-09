<?php

namespace Modules\AmenityReservations\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AmenityReservations\Database\Factories\ReservationSurchargeFactory;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeConcept;
use QCH\Core\Casts\TenantMoneyCast;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * S9 — links an admin-imposed surcharge (incident/damage charge) to the
 * standalone OPEN Collections charge it created on the reservation's unit.
 *
 * CRITICAL: the linked `charge` NEVER carries `amenity_reservation_id` — that
 * column is reserved exclusively for the reservation's OWN cost/deposit
 * charges (read by AmenityReservationChargeApplicationObserver to sync
 * `amount_outstanding`). A surcharge is a separate, real open debt on the
 * unit; traceability to the reservation flows ONLY through this table.
 */
#[ObservedBy([GlobalObserver::class])]
class ReservationSurcharge extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'reservation_surcharge';

    protected $fillable = [
        'reservation_id',
        'charge_id',
        'charge_concept_id',
        'amount',
        'description',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => TenantMoneyCast::class,
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(AmenityReservation::class, 'reservation_id');
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class, 'charge_id');
    }

    public function charge_concept(): BelongsTo
    {
        return $this->belongsTo(ChargeConcept::class, 'charge_concept_id');
    }

    protected static function newFactory(): ReservationSurchargeFactory
    {
        return ReservationSurchargeFactory::new();
    }
}
