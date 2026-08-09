<?php

namespace Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Clinic\Database\Factories\HealthCenterFactory;
use Modules\Municipality\Models\Municipality;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class HealthCenter extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'health_center';

    protected $fillable = [
        'name',
        'clues_code',
        'municipality_id',
        'address',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class, 'municipality_id');
    }

    protected static function newFactory(): HealthCenterFactory
    {
        return HealthCenterFactory::new();
    }
}
