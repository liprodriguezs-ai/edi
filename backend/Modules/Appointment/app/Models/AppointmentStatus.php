<?php

namespace Modules\Appointment\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Central FSM catalog for the appointment lifecycle. Fixed UUIDs, seeded once
 * by CatalogSeeder, read-only for tenant roles. The occupying set is mirrored in
 * the appointment_practitioner_no_overlap constraint.
 */
#[ObservedBy([GlobalObserver::class])]
class AppointmentStatus extends CentralCatalogModel
{
    protected $table = 'appointment_status';

    public const SCHEDULED = 'ed600001-0000-4000-8000-000000000001';

    public const CONFIRMED = 'ed600001-0000-4000-8000-000000000002';

    public const ARRIVED = 'ed600001-0000-4000-8000-000000000003';

    public const FULFILLED = 'ed600001-0000-4000-8000-000000000004';

    public const CANCELLED = 'ed600001-0000-4000-8000-000000000005';

    public const NO_SHOW = 'ed600001-0000-4000-8000-000000000006';

    /** Statuses whose time range still occupies the practitioner. */
    public const OCCUPYING = [self::SCHEDULED, self::CONFIRMED, self::ARRIVED];

    /** Statuses that admit no further transition or edit. */
    public const TERMINAL = [self::FULFILLED, self::CANCELLED, self::NO_SHOW];

    protected $fillable = [
        'id',
        'key',
        'name',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
