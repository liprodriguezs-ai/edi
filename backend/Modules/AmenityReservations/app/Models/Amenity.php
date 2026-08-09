<?php

namespace Modules\AmenityReservations\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\AmenityReservations\Database\Factories\AmenityFactory;
use Modules\Treasury\Models\BankAccount;
use QCH\Core\Casts\TenantMoneyCast;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class Amenity extends BaseModel implements HasMedia
{
    use BelongsToTenant;
    use HasFactory;
    use InteractsWithMedia;

    protected $table = 'amenity';

    protected $fillable = [
        'name',
        'description',
        'restrictions',
        'capacity',
        'available_weekdays',
        'rules_text',
        'out_of_service',
        'out_of_service_reason',
        'has_cost',
        'cost_amount',
        'requires_deposit',
        'deposit_amount',
        'deposit_due_days',
        'bank_account_id',
        'requires_approval',
        'reservation_mode_id',
        'target_type',
        'target_selector',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'available_weekdays' => 'array',
            'out_of_service' => 'boolean',
            'has_cost' => 'boolean',
            'cost_amount' => TenantMoneyCast::class,
            'requires_deposit' => 'boolean',
            'deposit_amount' => TenantMoneyCast::class,
            'deposit_due_days' => 'integer',
            'requires_approval' => 'boolean',
            'target_selector' => 'array',
        ];
    }

    public function slots(): HasMany
    {
        return $this->hasMany(AmenitySlot::class, 'amenity_id');
    }

    public function blackouts(): HasMany
    {
        return $this->hasMany(AmenityBlackout::class, 'amenity_id');
    }

    public function bank_account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function reservation_mode(): BelongsTo
    {
        return $this->belongsTo(ReservationMode::class, 'reservation_mode_id');
    }

    /**
     * Register media collections for amenities.
     * - gallery: multiple photos shown in the resident catalog.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('gallery')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
    }

    protected static function newFactory(): AmenityFactory
    {
        return AmenityFactory::new();
    }
}
