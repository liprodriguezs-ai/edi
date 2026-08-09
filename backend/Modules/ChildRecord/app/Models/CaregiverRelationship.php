<?php

namespace Modules\ChildRecord\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\ChildRecord\Database\Factories\CaregiverRelationshipFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Tenant-scoped, admin-editable kinship catalog (D5).
 */
#[ObservedBy([GlobalObserver::class])]
class CaregiverRelationship extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'caregiver_relationship';

    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): CaregiverRelationshipFactory
    {
        return CaregiverRelationshipFactory::new();
    }
}
