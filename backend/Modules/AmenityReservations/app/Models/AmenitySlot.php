<?php

namespace Modules\AmenityReservations\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AmenityReservations\Database\Factories\AmenitySlotFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class AmenitySlot extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'amenity_slot';

    protected $fillable = [
        'amenity_id',
        'weekday',
        'start_time',
        'end_time',
        'capacity_override',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'capacity_override' => 'integer',
        ];
    }

    public function amenity(): BelongsTo
    {
        return $this->belongsTo(Amenity::class, 'amenity_id');
    }

    protected static function newFactory(): AmenitySlotFactory
    {
        return AmenitySlotFactory::new();
    }
}
