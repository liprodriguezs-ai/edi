<?php

namespace Modules\Stimulation\Models;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Clinic\Models\HealthCenter;
use Modules\Stimulation\Database\Factories\StimulationGroupFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Early stimulation group at a health center, with enrollment and attendance
 * (REQ-SG-001). is_active gates enrollment/attendance writes only, never
 * reads (design D11).
 */
#[ObservedBy([GlobalObserver::class])]
class StimulationGroup extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'stimulation_group';

    protected $fillable = [
        'name',
        'stimulation_group_type_id',
        'health_center_id',
        'promoter_user_id',
        'start_date',
        'end_date',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function stimulation_group_type(): BelongsTo
    {
        return $this->belongsTo(StimulationGroupType::class, 'stimulation_group_type_id');
    }

    public function health_center(): BelongsTo
    {
        return $this->belongsTo(HealthCenter::class, 'health_center_id');
    }

    /**
     * D6 — the group promoter (promotora) may not hold a professional
     * license, so the reference is a plain tenant user, not a practitioner
     * profile.
     */
    public function promoter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'promoter_user_id')
            ->withoutGlobalScope(UserTenantScope::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(StimulationGroupMember::class, 'stimulation_group_id');
    }

    protected static function newFactory(): StimulationGroupFactory
    {
        return StimulationGroupFactory::new();
    }
}
