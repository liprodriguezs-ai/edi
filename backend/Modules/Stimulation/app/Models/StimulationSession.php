<?php

namespace Modules\Stimulation\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ChildRecord\Models\Child;
use Modules\ChildRecord\Models\Visit;
use Modules\Clinic\Models\PractitionerProfile;
use Modules\Stimulation\Database\Factories\StimulationSessionFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Early stimulation session (interacción oportuna): concrete record with the
 * four official sections as column groups (design D1) — the system does NOT
 * model this as a generic document/section/question form engine. Subject:
 * exactly one of child()/stimulation_group() (D5).
 */
#[ObservedBy([GlobalObserver::class])]
class StimulationSession extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'stimulation_session';

    protected $fillable = [
        'child_id',
        'stimulation_group_id',
        'visit_id',
        'session_date',
        'practitioner_id',
        'integration_dynamic_id',
        'integration_material',
        'integration_song',
        'preparation_song',
        'core_stimulation_area_id',
        'core_dynamic_id',
        'core_activity',
        'core_material',
        'core_song',
        'relaxation_notes',
        'observations',
    ];

    protected function casts(): array
    {
        return [
            'session_date' => 'date',
        ];
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class, 'child_id');
    }

    public function stimulation_group(): BelongsTo
    {
        return $this->belongsTo(StimulationGroup::class, 'stimulation_group_id');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class, 'visit_id');
    }

    /**
     * D6 — the clinical actor is the licensed professional's profile, not the
     * raw login user.
     */
    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(PractitionerProfile::class, 'practitioner_id');
    }

    public function integration_dynamic(): BelongsTo
    {
        return $this->belongsTo(StimulationDynamic::class, 'integration_dynamic_id');
    }

    public function core_dynamic(): BelongsTo
    {
        return $this->belongsTo(StimulationDynamic::class, 'core_dynamic_id');
    }

    public function core_stimulation_area(): BelongsTo
    {
        return $this->belongsTo(StimulationArea::class, 'core_stimulation_area_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StimulationSessionMovement::class, 'session_id');
    }

    public function relaxation_zones(): HasMany
    {
        return $this->hasMany(StimulationSessionRelaxationZone::class, 'session_id');
    }

    protected static function newFactory(): StimulationSessionFactory
    {
        return StimulationSessionFactory::new();
    }
}
