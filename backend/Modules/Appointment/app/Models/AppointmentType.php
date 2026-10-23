<?php

namespace Modules\Appointment\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Central catalog of appointment kinds. SCREENING opens the EDI capture when
 * care starts; default_duration_minutes sizes the booked range.
 */
#[ObservedBy([GlobalObserver::class])]
class AppointmentType extends CentralCatalogModel
{
    protected $table = 'appointment_type';

    public const SCREENING = 'ed600002-0000-4000-8000-000000000001';

    public const STIMULATION = 'ed600002-0000-4000-8000-000000000002';

    public const CHECK_UP = 'ed600002-0000-4000-8000-000000000003';

    protected $fillable = [
        'id',
        'key',
        'name',
        'default_duration_minutes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'default_duration_minutes' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
