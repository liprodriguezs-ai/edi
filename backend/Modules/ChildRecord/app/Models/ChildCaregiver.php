<?php

namespace Modules\ChildRecord\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ChildRecord\Database\Factories\ChildCaregiverFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Pivot child<->caregiver with kinship and primary-contact flag. The
 * exactly-one-primary invariant (REQ-CH-006) is enforced by ChildCaregiverService.
 */
#[ObservedBy([GlobalObserver::class])]
class ChildCaregiver extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'child_caregiver';

    protected $fillable = [
        'child_id',
        'caregiver_id',
        'caregiver_relationship_id',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class, 'child_id');
    }

    public function caregiver(): BelongsTo
    {
        return $this->belongsTo(Caregiver::class, 'caregiver_id');
    }

    public function caregiver_relationship(): BelongsTo
    {
        return $this->belongsTo(CaregiverRelationship::class, 'caregiver_relationship_id');
    }

    protected static function newFactory(): ChildCaregiverFactory
    {
        return ChildCaregiverFactory::new();
    }
}
