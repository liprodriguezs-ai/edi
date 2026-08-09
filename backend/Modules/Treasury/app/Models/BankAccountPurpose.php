<?php

namespace Modules\Treasury\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use QCH\Core\Models\GlobalObserver;
use QCH\Core\Models\TenantCatalogModel;

#[ObservedBy([GlobalObserver::class])]
class BankAccountPurpose extends TenantCatalogModel
{
    use HasFactory;

    protected $table = 'bank_account_purpose';

    protected $fillable = [
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

    public function bank_accounts(): HasMany
    {
        return $this->hasMany(BankAccount::class, 'bank_account_purpose_id');
    }
}
