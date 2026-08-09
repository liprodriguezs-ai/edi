<?php

namespace Modules\Collaborator\Models;

use App\Models\MaritalStatus;
use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Collaborator\Observers\CollaboratorObserver;
use Modules\Company\Models\Company;
use QCH\Core\Casts\TenantMoneyCast;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([CollaboratorObserver::class, GlobalObserver::class])]
class Collaborator extends BaseModel implements HasMedia
{
    use BelongsToTenant;
    use HasFactory;
    use InteractsWithMedia;

    /**
     * Standard relations to eager-load when returning a Collaborator.
     * Referenced from controllers and services to avoid duplication.
     */
    public const DEFAULT_RELATIONS = [
        'user',
        'company',
        'marital_status',
    ];

    protected $table = 'collaborator';

    protected $appends = ['full_name'];

    protected $dates = [
        'date_of_birth',
        'contract_at',
        'contract_end_at',
    ];

    public function getFullNameAttribute(): string
    {
        $parts = array_filter([
            $this->attributes['name'] ?? null,
            $this->attributes['last_name'] ?? null,
            $this->attributes['second_last_name'] ?? null,
        ]);

        return implode(' ', $parts) ?: '';
    }

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'name',
        'last_name',
        'second_last_name',
        'email',
        'phone',
        'create_as_user',
        'job_position_id',
        'area_id',
        'contract_type_id',
        'rfc',
        'curp',
        'contracted',
        'date_of_birth',
        'contract_at',
        'contract_end_at',
        'gender',
        'number',
        'nss',
        'company_id',
        'nationality_id',
        'birth_place',
        'marital_status_id',
        'marriage_record_number',
        'address',
        'official_identification',
        'beneficiary',
        'work_shift_id',
        'specific_schedule',
        // Kivo7 employee fields
        'employee_position_id',
        'pay_frequency_id',
        'employee_shift_id',
        'base_salary',
        'work_days',
        'hire_date',
        'termination_date',
        'termination_reason',
        'is_active',
    ];

    protected $casts = [
        'create_as_user' => 'boolean',
        'contracted' => 'boolean',
        'is_active' => 'boolean',
        'date_of_birth' => 'date:Y-m-d',
        'contract_at' => 'date:Y-m-d',
        'contract_end_at' => 'date:Y-m-d',
        'hire_date' => 'date:Y-m-d',
        'termination_date' => 'date:Y-m-d',
        'work_days' => 'array',
        'base_salary' => TenantMoneyCast::class,
    ];

    /**
     * User lives in central DB; loaded without tenant scope so the relation
     * resolves by id. Tenant isolation is guaranteed by Collaborator being in tenant DB.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')
            ->withoutGlobalScope(UserTenantScope::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function marital_status(): BelongsTo
    {
        return $this->belongsTo(MaritalStatus::class, 'marital_status_id');
    }

    public function employee_position(): BelongsTo
    {
        return $this->belongsTo(EmployeePosition::class, 'employee_position_id');
    }

    public function pay_frequency(): BelongsTo
    {
        return $this->belongsTo(PayFrequency::class, 'pay_frequency_id');
    }

    public function employee_shift(): BelongsTo
    {
        return $this->belongsTo(EmployeeShift::class, 'employee_shift_id');
    }

    public function reportColumns()
    {
        return [
            'No. de colaborador' => function ($result) {
                return $result->number;
            },
            'Nombre' => function ($result) {
                return $result->name;
            },
            'Primer apellido' => function ($result) {
                return $result->last_name;
            },
            'Segundo Apellido' => function ($result) {
                return $result->second_last_name;
            },
            'Correo electrónico' => function ($result) {
                return $result->email;
            },
            'Teléfono' => function ($result) {
                return $result->phone;
            },
            'Fecha de nacimiento' => function ($result) {
                return $result->date_of_birth ? $result->date_of_birth->format('d/m/Y') : '';
            },
            'Género' => function ($result) {
                return $result->gender;
            },
            'Estado civil' => function ($result) {
                return $result->marital_status?->name;
            },
            'Número de acta de matrimonio' => function ($result) {
                return $result->marriage_record_number;
            },
            'RFC' => function ($result) {
                return $result->rfc;
            },
            'CURP' => function ($result) {
                return $result->curp;
            },
            'NSS' => function ($result) {
                return $result->nss;
            },
            'Identificación oficial' => function ($result) {
                return $result->official_identification;
            },
            'Fecha de contrato' => function ($result) {
                return $result->contract_at ? $result->contract_at->format('d/m/Y') : '';
            },
            'Fecha de fin de contrato' => function ($result) {
                return $result->contract_end_at ? $result->contract_end_at->format('d/m/Y') : '';
            },
            'Contratado' => function ($result) {
                return $result->contracted ? 'Sí' : 'No';
            },
            'Fecha de registro' => function ($result) {
                return $result->created_at ? $result->created_at->format('d/m/Y') : '';
            },
            'Fecha de actualización' => function ($result) {
                return $result->updated_at ? $result->updated_at->format('d/m/Y') : '';
            },
        ];
    }

    /**
     * Register media collections for collaborators (employees).
     * - documents: private employment documents (contract, INE, etc.).
     *   PRIVATE — not publicly accessible; gated by board/admin/treasurer permission.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('documents')
            ->acceptsMimeTypes([
                'application/pdf',
                'image/jpeg',
                'image/png',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])
            ->useDisk('private');
    }

    /**
     * Columnas de empresa para la hoja "Empresa" del Excel de colaboradores.
     * Se usa una sola vez con los datos de la empresa principal del tenant.
     */
    public function companyReportColumns(): array
    {
        return [
            'RFC' => fn ($r) => $r->rfc,
            'Razón social' => fn ($r) => $r->legal_name,
            'Nombre de la empresa' => fn ($r) => $r->name,
            'Registro patronal' => fn ($r) => $r->employer_registration,
            'Domicilio fiscal' => fn ($r) => $r->fiscal_address,
            'Representante legal' => fn ($r) => $r->legal_representative,
            'Número de acta constitutiva' => fn ($r) => $r->constitutive_deed_number,
            'Fecha de acta constitutiva' => fn ($r) => $r->constitutive_deed_date
                ? Carbon::parse($r->constitutive_deed_date)->format('d/m/Y')
                : '',
            'Número de notario' => fn ($r) => $r->constitutive_notary_number,
            'Nombre del notario' => fn ($r) => $r->constitutive_notary_name,
            'Estado del notario' => fn ($r) => $r->constitutive_notary_state,
        ];
    }
}
