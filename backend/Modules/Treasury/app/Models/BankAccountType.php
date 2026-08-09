<?php

namespace Modules\Treasury\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class BankAccountType extends CentralCatalogModel
{
    use HasFactory;

    const BANK = '8ecf5da1-2d96-4fcf-815b-1202467856cf';

    const CASH = '1b350e3c-1f75-4bda-bb9d-86093f5c2948';

    const INVESTMENT = '797f5cec-6837-4a69-9126-685671a2fd92';

    protected $table = 'bank_account_type';

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

    public function bank_accounts(): HasMany
    {
        return $this->hasMany(BankAccount::class, 'bank_account_type_id');
    }
}
