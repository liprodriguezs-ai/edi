<?php

namespace Modules\AmenityReservations\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class ReservationMode extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'reservation_mode';

    // Behavior-selector constants — CENTRAL/shared catalog (no tenant_id;
    // fixed UUIDs seeded once by CatalogSeeder). Mirrors AmountMode/Periodicity.
    public const BY_DAY = 'e7d9abab-af97-4753-a87d-bcebf5026af7';

    public const BY_SLOT = '70dddcdd-542b-4595-ad66-1e34e27df7e4';

    protected $fillable = [
        'id',
        'name',
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
