<?php

namespace Modules\Treasury\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use QCH\Core\Casts\TenantMoneyCast;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class BankAccount extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'bank_account';

    /**
     * current_balance is NEVER mass-assignable (§9.6 r3): it is recomputed
     * transactionally by PaymentService/ExpenseService/BankAccountService via
     * direct property assignment, and initialised by the creating() hook.
     */
    protected $fillable = [
        'name',
        'bank_account_type_id',
        'bank_account_purpose_id',
        'bank',
        'account_number',
        'clabe',
        'card_number',
        'currency',
        'initial_balance',
        'is_primary',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'initial_balance' => TenantMoneyCast::class,
            'current_balance' => TenantMoneyCast::class,
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * When a BankAccount is created, current_balance must equal initial_balance.
     * This is enforced here so it applies regardless of path (API, seeder, factory).
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (BankAccount $account): void {
            if ($account->current_balance === null || (int) $account->getRawOriginal('current_balance') === 0) {
                $account->current_balance = $account->initial_balance;
            }
        });
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(BankAccountType::class, 'bank_account_type_id');
    }

    public function purpose(): BelongsTo
    {
        return $this->belongsTo(BankAccountPurpose::class, 'bank_account_purpose_id');
    }

    public function reportColumns(): array
    {
        return [
            'Nombre' => function ($result) {
                return $result->name;
            },
            'Banco' => function ($result) {
                return $result->bank;
            },
            'No. de cuenta' => function ($result) {
                return $result->account_number;
            },
            'CLABE' => function ($result) {
                return $result->clabe;
            },
            // Exported masked on purpose: the full card number is sensitive data.
            'Tarjeta' => function ($result) {
                return $result->card_number ? '•••• '.mb_substr($result->card_number, -4) : '';
            },
            'Tipo' => function ($result) {
                return $result->type?->name;
            },
            'Propósito' => function ($result) {
                return $result->purpose?->name;
            },
            'Moneda' => function ($result) {
                return $result->currency;
            },
            'Saldo inicial' => function ($result) {
                return $result->initial_balance ? (float) $result->initial_balance->formatByDecimal() : 0;
            },
            'Saldo actual' => function ($result) {
                return $result->current_balance ? (float) $result->current_balance->formatByDecimal() : 0;
            },
            'Principal' => function ($result) {
                return $result->is_primary ? 'Sí' : 'No';
            },
            'Activa' => function ($result) {
                return $result->is_active ? 'Sí' : 'No';
            },
            'F. creación' => function ($result) {
                return $result->created_at ? $result->created_at->format('d/m/Y') : '';
            },
        ];
    }
}
