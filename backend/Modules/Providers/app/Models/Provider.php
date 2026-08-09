<?php

namespace Modules\Providers\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Treasury\Models\Expense;
use QCH\Core\Casts\TenantMoneyCast;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class Provider extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'provider';

    protected $fillable = [
        'provider_type_id',
        'name',
        'rfc',
        'provider_service_type_id',
        'user_id',
        'contact_person',
        'phone',
        'email',
        'address',
        'is_recurring',
        'frequency',
        'estimated_amount',
        'usual_payment_day',
        'clabe',
        'bank',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_recurring' => 'boolean',
            'is_active' => 'boolean',
            'usual_payment_day' => 'integer',
            'estimated_amount' => TenantMoneyCast::class,
        ];
    }

    public function providerType(): BelongsTo
    {
        return $this->belongsTo(ProviderType::class, 'provider_type_id');
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ProviderServiceType::class, 'provider_service_type_id');
    }

    /**
     * Payment history — expenses where provider_id = this provider's id.
     * Loaded lazily to avoid circular dependency at class-load time.
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'provider_id');
    }

    /**
     * §10.4.1 — optional link to the person behind the provider (central user).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reportColumns(): array
    {
        return [
            'Nombre' => function ($result) {
                return $result->name;
            },
            'RFC' => function ($result) {
                return $result->rfc;
            },
            'Tipo' => function ($result) {
                return $result->providerType?->name;
            },
            'Tipo de servicio' => function ($result) {
                return $result->serviceType?->name;
            },
            'Contacto' => function ($result) {
                return $result->contact_person;
            },
            'Teléfono' => function ($result) {
                return $result->phone;
            },
            'Correo' => function ($result) {
                return $result->email;
            },
            'Recurrente' => function ($result) {
                return $result->is_recurring ? 'Sí' : 'No';
            },
            'Monto estimado' => function ($result) {
                return $result->estimated_amount ? (float) $result->estimated_amount->formatByDecimal() : 0;
            },
            'Día usual de pago' => function ($result) {
                return $result->usual_payment_day;
            },
            'CLABE' => function ($result) {
                return $result->clabe;
            },
            'Banco' => function ($result) {
                return $result->bank;
            },
            'Activo' => function ($result) {
                return $result->is_active ? 'Sí' : 'No';
            },
            'F. creación' => function ($result) {
                return $result->created_at ? $result->created_at->format('d/m/Y') : '';
            },
        ];
    }
}
