<?php

namespace Modules\Treasury\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class BeneficiaryType extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'beneficiary_type';

    public const EMPLOYEE = '228511f6-e8fa-40e4-b64e-d4b91cb46c2e';

    public const PROVIDER = '32b02df7-bc4e-4b2b-beb3-aa831dd01d8f';

    public const OTHER = 'fa5eca75-833b-4a56-99da-72546cc5ffa9';

    protected $fillable = [
        'id',
        'name',
        'order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'order' => 'integer',
        ];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'beneficiary_type_id');
    }
}
