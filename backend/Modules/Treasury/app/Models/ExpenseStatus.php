<?php

namespace Modules\Treasury\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class ExpenseStatus extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'expense_status';

    public const PAID = 'aa002278-0652-43e0-8333-a3cf47e5dadd';

    public const VOIDED = '1d92fd01-8b94-4ee5-9ebf-72ca90c86842';

    protected $fillable = [
        'id',
        'name',
        'color',
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
        return $this->hasMany(Expense::class, 'expense_status_id');
    }
}
