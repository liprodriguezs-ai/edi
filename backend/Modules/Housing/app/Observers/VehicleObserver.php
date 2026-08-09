<?php

namespace Modules\Housing\Observers;

use App\Notifications\Concerns\NotifiesResidents;
use Modules\Housing\Models\Vehicle;

/**
 * VehicleObserver — §6.7: notify the unit's titular de pago when a vehicle is registered.
 */
class VehicleObserver
{
    use NotifiesResidents;

    public function created(Vehicle $vehicle): void
    {
        $titularId = $this->unitBillingUserId($vehicle->unit_id);
        if (! $titularId) {
            return;
        }

        $this->notifyUsers(
            [$titularId],
            'Vehículo registrado',
            'Se registró un vehículo'.($vehicle->plate ? " con placa {$vehicle->plate}" : '').' en tu unidad.',
            ['click_action' => '/vehicle', 'vehicle_id' => $vehicle->id, 'unit_id' => $vehicle->unit_id],
        );
    }
}
