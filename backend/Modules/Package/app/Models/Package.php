<?php

namespace Modules\Package\Models;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Housing\Models\Unit;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class Package extends BaseModel implements HasMedia
{
    use BelongsToTenant;
    use InteractsWithMedia;

    protected $table = 'package';

    /**
     * pickup_code is the retrieval credential. Hidden from default serialization
     * so guards (who log it to open the gate) cannot expose it to other residents.
     * Only the owning resident's active unit members get it via makeVisible.
     *
     * @var list<string>
     */
    protected $hidden = ['pickup_code'];

    protected $fillable = [
        'unit_id',
        'package_status_id',
        'recipient_user_id',
        'recipient_name',
        'package_carrier_id',
        'carrier_other',
        'package_type_id',
        'tracking_number',
        'description',
        'storage_location',
        'pickup_code',
        'received_at',
        'received_by_user_id',
        'notified_at',
        'reminded_at',
        'picked_up_at',
        'picked_up_by_user_id',
        'pickup_proxy_name',
        'pickup_proxy_relationship',
        'incident_reason',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'notified_at' => 'datetime',
            'reminded_at' => 'datetime',
            'picked_up_at' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photo')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);

        $this->addMediaCollection('pickup_evidence')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(PackageStatus::class, 'package_status_id');
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(PackageCarrier::class, 'package_carrier_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(PackageType::class, 'package_type_id');
    }

    public function recipient_user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /**
     * The guard who received the package. No FK on purpose — mirrors the
     * access_log.guard_user_id pattern: the user may be soft-deleted but the
     * record must stay intact.
     */
    public function received_by_user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id')
            ->withoutGlobalScope(UserTenantScope::class);
    }

    /**
     * The guard who handed over the package. No FK — same rationale as above.
     */
    public function picked_up_by_user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'picked_up_by_user_id')
            ->withoutGlobalScope(UserTenantScope::class);
    }
}
