<?php

namespace Modules\AmenityReservations\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\AmenityReservations\Database\Factories\AmenityReservationFactory;
use Modules\AmenityReservations\Handlers\AmenityReservationEarmarkHandler;
use Modules\Collections\Models\Payment;
use Modules\Housing\Models\Unit;
use QCH\Core\Casts\TenantMoneyCast;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A resident booking of an amenity (S5 — data model only; creation service +
 * FSM transitions land in S6/S7). `holds_slot` is the flag the double-booking
 * predicate keys on: true while the reservation occupies the amenity/date
 * (by_day) or amenity/slot/date (by_slot); false once cancelled/rejected so
 * the date/slot frees up for re-booking.
 */
#[ObservedBy([GlobalObserver::class])]
class AmenityReservation extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'amenity_reservation';

    protected $fillable = [
        'amenity_id',
        'unit_id',
        'user_id',
        'reservation_date',
        'slot_id',
        'start_at',
        'end_at',
        'reservation_status_id',
        'holds_slot',
        'approved_by',
        'approved_at',
        'rejected_reason',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'rules_accepted_at',
        'cost_amount',
        'deposit_amount',
        'amount_outstanding',
        'balance_due_date',
        'payment_deadline',
    ];

    protected function casts(): array
    {
        return [
            'reservation_date' => 'date',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'holds_slot' => 'boolean',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'rules_accepted_at' => 'datetime',
            'cost_amount' => TenantMoneyCast::class,
            'deposit_amount' => TenantMoneyCast::class,
            'amount_outstanding' => TenantMoneyCast::class,
            'balance_due_date' => 'date',
            'payment_deadline' => 'datetime',
            'has_pending_payment' => 'boolean',
        ];
    }

    public function amenity(): BelongsTo
    {
        return $this->belongsTo(Amenity::class, 'amenity_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(AmenitySlot::class, 'slot_id');
    }

    public function reservation_status(): BelongsTo
    {
        return $this->belongsTo(ReservationStatus::class, 'reservation_status_id');
    }

    /**
     * S9 — admin-imposed surcharges (incident/damage charges) billed against
     * this reservation's unit. See ReservationSurcharge's docblock: the
     * linked charges NEVER carry amenity_reservation_id, so they never
     * affect amount_outstanding — this relation is read-only traceability.
     */
    public function surcharges(): HasMany
    {
        return $this->hasMany(ReservationSurcharge::class, 'reservation_id');
    }

    /**
     * A1/A2/B — every earmarked Payment funding THIS reservation (any status),
     * scoped by the same (earmark_type, earmark_id) pair every earmark-payment
     * query in ReservationService/AmenityReservationEarmarkHandler already
     * filters on manually. Powers the cron's expiry-grace prefilter, the
     * cancel/expire terminal sweep, and the computed `has_pending_payment` flag.
     */
    public function earmark_payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'earmark_id')
            ->where('earmark_type', AmenityReservationEarmarkHandler::EARMARK_TYPE);
    }

    protected static function newFactory(): AmenityReservationFactory
    {
        return AmenityReservationFactory::new();
    }
}
