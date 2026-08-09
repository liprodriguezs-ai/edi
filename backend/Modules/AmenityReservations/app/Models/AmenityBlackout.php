<?php

namespace Modules\AmenityReservations\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AmenityReservations\Database\Factories\AmenityBlackoutFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class AmenityBlackout extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'amenity_blackout';

    protected $fillable = [
        'amenity_id',
        'start_date',
        'end_date',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function amenity(): BelongsTo
    {
        return $this->belongsTo(Amenity::class, 'amenity_id');
    }

    protected static function newFactory(): AmenityBlackoutFactory
    {
        return AmenityBlackoutFactory::new();
    }
}
