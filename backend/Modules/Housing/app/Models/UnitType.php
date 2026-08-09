<?php

namespace Modules\Housing\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use QCH\Core\Models\GlobalObserver;
use QCH\Core\Models\TenantCatalogModel;

#[ObservedBy([GlobalObserver::class])]
class UnitType extends TenantCatalogModel
{
    use HasFactory;

    protected $table = 'unit_type';

    protected $fillable = [
        'name',
        'order',
        'is_active',
        'requires_floor',
    ];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'is_active' => 'boolean',
            'requires_floor' => 'boolean',
        ];
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class, 'unit_type_id');
    }
}
