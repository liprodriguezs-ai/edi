<?php

namespace Modules\Stimulation\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Stimulation\Database\Factories\StimulationGroupTypeFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Tenant-scoped, admin-editable catalog of stimulation group types.
 */
#[ObservedBy([GlobalObserver::class])]
class StimulationGroupType extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'stimulation_group_type';

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

    protected static function newFactory(): StimulationGroupTypeFactory
    {
        return StimulationGroupTypeFactory::new();
    }
}
