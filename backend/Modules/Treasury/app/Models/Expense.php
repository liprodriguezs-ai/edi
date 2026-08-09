<?php

namespace Modules\Treasury\Models;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Collaborator\Models\Collaborator;
use Modules\Collections\Models\PaymentMethod;
use Modules\Providers\Models\Provider;
use QCH\Core\Casts\TenantMoneyCast;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class Expense extends BaseModel implements HasMedia
{
    use BelongsToTenant;
    use HasFactory;
    use InteractsWithMedia;

    protected $table = 'expense';

    protected $fillable = [
        'concept',
        'description',
        'paid_at',
        'amount',
        'expense_category_id',
        'beneficiary_type_id',
        'employee_id',
        'provider_id',
        'beneficiary_text',
        'year',
        'month',
        'fortnight',
        'payment_method_id',
        'bank_account_id',
        'reference',
        'community_project_id',
        'authorized_by_user_id',
        'expense_status_id',
        'void_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'date:Y-m-d',
            'amount' => TenantMoneyCast::class,
            'year' => 'integer',
            'month' => 'integer',
            'fortnight' => 'integer',
            'usual_payment_day' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function beneficiaryType(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryType::class, 'beneficiary_type_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class, 'employee_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'provider_id');
    }

    public function payment_method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function bank_account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(ExpenseStatus::class, 'expense_status_id');
    }

    /**
     * Authorized by — central user. Bypass UserTenantScope since this is a cross-context reference.
     */
    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_user_id')
            ->withoutGlobalScope(UserTenantScope::class);
    }

    public function reportColumns(): array
    {
        return [
            'Concepto' => function ($result) {
                return $result->concept;
            },
            'Descripción' => function ($result) {
                return $result->description;
            },
            'F. de pago' => function ($result) {
                return $result->paid_at ? $result->paid_at->format('d/m/Y') : '';
            },
            'Monto' => function ($result) {
                return $result->amount ? (float) $result->amount->formatByDecimal() : 0;
            },
            'Categoría' => function ($result) {
                return $result->category?->name;
            },
            'Proveedor' => function ($result) {
                return $result->provider?->name;
            },
            'Beneficiario' => function ($result) {
                if ($result->employee) {
                    return trim($result->employee->name.' '.$result->employee->last_name);
                }

                return $result->beneficiary_text ?? '';
            },
            'Año' => function ($result) {
                return $result->year;
            },
            'Mes' => function ($result) {
                return $result->month;
            },
            'Método de pago' => function ($result) {
                return $result->payment_method?->name;
            },
            'Cuenta bancaria' => function ($result) {
                return $result->bank_account?->name;
            },
            'Referencia' => function ($result) {
                return $result->reference;
            },
            'Estado' => function ($result) {
                return $result->status?->name;
            },
            'F. creación' => function ($result) {
                return $result->created_at ? $result->created_at->format('d/m/Y') : '';
            },
        ];
    }

    /**
     * Register media collections for expenses (spec §9.4.2, §9.4.3).
     * - receipt: single comprobante de gasto (PDF or image).
     * - cfdi: factura CFDI — XML + PDF (up to 2 files).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('receipt')
            ->acceptsMimeTypes([
                'application/pdf',
                'image/jpeg',
                'image/png',
                'image/webp',
            ])
            ->singleFile();

        $this->addMediaCollection('cfdi')
            ->acceptsMimeTypes([
                'application/pdf',
                'application/xml',
                'text/xml',
            ]);
    }
}
