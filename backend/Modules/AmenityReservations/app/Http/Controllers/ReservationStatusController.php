<?php

namespace Modules\AmenityReservations\Http\Controllers;

use Modules\AmenityReservations\Models\ReservationStatus;
use QCH\Core\Http\Controllers\Controller;

class ReservationStatusController extends Controller
{
    protected $model = ReservationStatus::class;

    protected $keyForPermission = 'reservation_status';

    public function sortableBy(): array
    {
        return ['order', 'name'];
    }

    public function filterableBy(): array
    {
        return ['is_active'];
    }
}
