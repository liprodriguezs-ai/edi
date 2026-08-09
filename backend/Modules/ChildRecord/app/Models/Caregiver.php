<?php

namespace Modules\ChildRecord\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ChildRecord\Database\Factories\CaregiverFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Adult PII (REQ-XC-005). Audited in full — see Child's note.
 */
#[ObservedBy([GlobalObserver::class])]
class Caregiver extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'caregiver';

    protected $fillable = [
        'first_name',
        'last_name_paternal',
        'last_name_maternal',
        'birth_date',
        'education_level_id',
        'occupation_id',
        'population_type_id',
        'phone',
        'email',
        'has_social_program',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'has_social_program' => 'boolean',
        ];
    }

    public function education_level(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class, 'education_level_id');
    }

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class, 'occupation_id');
    }

    public function population_type(): BelongsTo
    {
        return $this->belongsTo(PopulationType::class, 'population_type_id');
    }

    public function child_caregivers(): HasMany
    {
        return $this->hasMany(ChildCaregiver::class, 'caregiver_id');
    }

    protected static function newFactory(): CaregiverFactory
    {
        return CaregiverFactory::new();
    }
}
