<?php

namespace Modules\Collaborator\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Collaborator\Models\Collaborator;
use Orion\Http\Requests\Request;

class CollaboratorRequest extends Request
{
    /**
     * Allowed work_days values (§7.4.2 días laborales, English identifiers).
     */
    public const WORK_DAYS = [
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
    ];

    protected function prepareForValidation(): void
    {
        $this->merge($this->normalizeEmptyStringsToNull([
            'second_last_name',
            'email',
            'phone',
            'rfc',
            'curp',
            'nss',
            'date_of_birth',
            'contract_at',
            'contract_end_at',
            'gender',
            'birth_place',
            'marital_status_id',
            'address',
            'official_identification',
            'beneficiary',
            'specific_schedule',
            'number',
            'user_id',
            'employee_position_id',
            'pay_frequency_id',
            'employee_shift_id',
            'base_salary',
            'hire_date',
        ]));
    }

    /**
     * Rules shared by both store and update operations.
     *
     * Kivo7 employee fields (§7.4.2): position, hire date, base salary (minor
     * units, must be > 0), pay frequency, shift and work days. The legacy CRM
     * catalogs (job_position/area/contract_type/nationality/work_shift) are dead
     * in the Kivo7 domain and are no longer accepted.
     */
    public function commonRules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'second_last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            // §7.4.2: the phone must not duplicate a central user's phone — except
            // the user already linked to this employee record.
            'phone' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('user', 'phone')->ignore($this->linkedUserId()),
            ],
            'rfc' => ['nullable', 'string', 'min:12', 'max:13'],
            'curp' => ['nullable', 'string', 'size:18'],
            'nss' => ['nullable', 'string'],
            'contracted' => ['nullable', 'boolean'],
            'date_of_birth' => ['nullable', 'date'],
            'contract_at' => ['nullable', 'date'],
            'contract_end_at' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', Rule::in(['masculino', 'femenino'])],
            'birth_place' => ['nullable', 'string', 'max:255'],
            'marital_status_id' => ['nullable', 'uuid', 'exists:central.marital_status,id'],
            'address' => ['nullable', 'string', 'max:1000'],
            'official_identification' => ['nullable', 'string', 'max:255'],
            'beneficiary' => ['nullable', 'string', 'max:255'],
            'specific_schedule' => ['nullable', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:255'],
            'create_as_user' => ['sometimes', 'boolean'],
            // Kivo7 employee fields (§7.4.2). These catalogs are TENANT-SCOPED
            // (TenantCatalogModel + BelongsToTenant); the `exists` rule bypasses
            // the global TenantScope, so each rule is scoped to the current tenant
            // to prevent storing another tenant's catalog UUID (cross-tenant FK).
            'employee_position_id' => ['nullable', 'uuid', Rule::exists('employee_position', 'id')->where('tenant_id', tenant('id'))],
            'pay_frequency_id' => ['nullable', 'uuid', Rule::exists('pay_frequency', 'id')->where('tenant_id', tenant('id'))],
            'employee_shift_id' => ['nullable', 'uuid', Rule::exists('employee_shift', 'id')->where('tenant_id', tenant('id'))],
            'base_salary' => ['nullable', 'integer', 'min:1'],
            'work_days' => ['nullable', 'array'],
            'work_days.*' => ['string', Rule::in(self::WORK_DAYS)],
            'hire_date' => ['nullable', 'date'],
        ];
    }

    /**
     * Additional rules applied only when storing a new collaborator.
     */
    public function storeRules(): array
    {
        return [
            'user_id' => ['nullable', 'uuid'],
            // Un colaborador SIEMPRE debe tener nombre al crearse (evita registros vacíos/ghost).
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * The central user this employee record is (or is being) linked to, so the
     * phone-uniqueness rule does not reject the employee's own user phone.
     */
    private function linkedUserId(): ?string
    {
        $userId = $this->input('user_id');
        if (is_string($userId) && $userId !== '') {
            return $userId;
        }

        $collaboratorId = $this->route('collaborator');
        if ($collaboratorId === null) {
            return null;
        }

        return Collaborator::query()->whereKey($collaboratorId)->value('user_id');
    }

    private function normalizeEmptyStringsToNull(array $keys): array
    {
        $input = $this->all();
        $normalized = [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            $value = $input[$key];
            if (is_string($value) && trim($value) === '') {
                $normalized[$key] = null;
            }
        }

        return $normalized;
    }
}
