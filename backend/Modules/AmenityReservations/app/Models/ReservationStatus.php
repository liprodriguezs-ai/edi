<?php

namespace Modules\AmenityReservations\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class ReservationStatus extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'reservation_status';

    // Status key constants — CENTRAL/shared catalog (no tenant_id; fixed UUIDs
    // seeded once by CatalogSeeder and referenced by code across all tenants).
    public const PENDING_APPROVAL = '456b615d-5850-4227-a27b-3bdbdf8a4382';

    public const PENDING_PAYMENT = '9d539bb7-c391-44e4-be65-d98eab77987d';

    public const CONFIRMED = 'f0b6626d-c020-4ad6-81ae-f8efc94be927';

    public const CANCELLED = '21702d3e-69d5-4dc0-a92b-7a9ead5b1add';

    public const REJECTED = '8d9e2a44-6275-4395-aebd-f718711f5626';

    public const COMPLETED = 'b0ae85fc-ad98-4cce-b4c4-1a7e089c78be';

    public const NO_SHOW = '1210de11-e939-4814-aa48-6661a40689e5';

    protected $fillable = [
        'id',
        'name',
        'color',
        'order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'order' => 'integer',
        ];
    }
}
