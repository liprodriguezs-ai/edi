<?php

namespace Modules\Stimulation\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Stimulation\Database\Factories\MassageMovementFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Tenant-scoped, admin-editable catalog of massage movements used in the
 * preparation section of a stimulation session.
 */
#[ObservedBy([GlobalObserver::class])]
class MassageMovement extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'massage_movement';

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

    protected static function newFactory(): MassageMovementFactory
    {
        return MassageMovementFactory::new();
    }
}
