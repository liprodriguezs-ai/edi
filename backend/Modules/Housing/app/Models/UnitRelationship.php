<?php

namespace Modules\Housing\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class UnitRelationship extends CentralCatalogModel
{
    use HasFactory;

    // Key constants for stable reference in code (the 'key' column value per tenant)
    const OWNER = '0a8e0380-19c4-47e2-a7f1-2a1dfaa2c087';

    const CO_OWNER = 'b116d845-0e52-449f-9402-00ed6230d8f8';

    const RENTER = '023ebb8f-5f06-43cf-8964-bd1659e5d369';

    const FAMILY_RESIDENT = 'fc6ed62b-09fc-4720-87ff-5e4e20de4cdf';

    const BILLING_CONTACT = '647259b2-f599-4648-af69-592260db4251';

    protected $table = 'unit_relationship';

    protected $fillable = [
        'id',
        'name',
        'order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function unitUsers(): HasMany
    {
        return $this->hasMany(UnitUser::class, 'unit_relationship_id');
    }
}
