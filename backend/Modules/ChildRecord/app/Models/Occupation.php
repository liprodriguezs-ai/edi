<?php

namespace Modules\ChildRecord\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\ChildRecord\Database\Factories\OccupationFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Tenant-scoped, admin-editable occupation catalog (D5).
 */
#[ObservedBy([GlobalObserver::class])]
class Occupation extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'occupation';

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

    protected static function newFactory(): OccupationFactory
    {
        return OccupationFactory::new();
    }
}
