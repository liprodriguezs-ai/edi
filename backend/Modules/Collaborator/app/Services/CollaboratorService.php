<?php

namespace Modules\Collaborator\Services;

use App\Models\MaritalStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Collaborator\Models\Collaborator;
use Modules\Company\Models\Company;

class CollaboratorService
{
    public function create(array $data)
    {
        $createAsUser = (bool) ($data['create_as_user'] ?? false);
        unset($data['create_as_user']);

        $normalizedGender = $this->normalizeGenderValue($data['gender'] ?? null);

        $collaborator = new Collaborator;
        $collaborator->fill([
            'user_id' => $data['user_id'] ?? null,
            'name' => $data['name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'second_last_name' => $data['second_last_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'create_as_user' => $createAsUser,
            'rfc' => $data['rfc'] ?? null,
            'curp' => $data['curp'] ?? null,
            'contracted' => $data['contracted'] ?? false,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'contract_at' => $data['contract_at'] ?? null,
            'contract_end_at' => $data['contract_end_at'] ?? null,
            'gender' => $normalizedGender,
            'birth_place' => $data['birth_place'] ?? null,
            'marital_status_id' => $data['marital_status_id'] ?? null,
            'marriage_record_number' => $data['marriage_record_number'] ?? null,
            'address' => $data['address'] ?? null,
            'official_identification' => $data['official_identification'] ?? null,
            'beneficiary' => $data['beneficiary'] ?? null,
            'specific_schedule' => $data['specific_schedule'] ?? null,
            'number' => $data['number'] ?? null,
            'nss' => $data['nss'] ?? null,
            // Kivo7 employee fields (§7.4.2) — base_salary in minor units.
            'employee_position_id' => $data['employee_position_id'] ?? null,
            'pay_frequency_id' => $data['pay_frequency_id'] ?? null,
            'employee_shift_id' => $data['employee_shift_id'] ?? null,
            'base_salary' => $data['base_salary'] ?? null,
            'work_days' => $data['work_days'] ?? null,
            'hire_date' => $data['hire_date'] ?? null,
        ]);
        $collaborator->save();

        $collaborator->load(Collaborator::DEFAULT_RELATIONS);

        return $collaborator;
    }

    public function update(array $data, $key)
    {
        $collaborator = Collaborator::find($key);

        $payload = $this->normalizeUpdatePayload($data);

        $collaborator->update($payload);

        $collaborator->refresh();
        $collaborator->load(Collaborator::DEFAULT_RELATIONS);

        return $collaborator;
    }

    private function normalizeGenderValue(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $normalized = Str::of($value)
            ->lower()
            ->ascii()
            ->replace(['-', ' '], '_')
            ->value();

        return match ($normalized) {
            'male', 'masculino', 'hombre', 'varon', 'm', 'trabajador' => 'masculino',
            'female', 'femenino', 'mujer', 'f', 'trabajadora' => 'femenino',
            default => null,
        };
    }

    private function normalizeUpdatePayload(array $data): array
    {
        if (array_key_exists('create_as_user', $data)) {
            $data['create_as_user'] = (bool) $data['create_as_user'];
        }

        if (array_key_exists('gender', $data)) {
            $data['gender'] = $this->normalizeGenderValue($data['gender'] ?? null);
        } else {
            unset($data['gender']);
        }

        if (array_key_exists('contracted', $data)) {
            if ($data['contracted'] === null) {
                unset($data['contracted']);
            } else {
                $data['contracted'] = (bool) $data['contracted'];
            }
        }

        foreach (['name', 'last_name'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === null) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    private function resolveMaritalStatusId(?string $statusName): ?string
    {
        if (! $statusName || trim($statusName) === '') {
            return null;
        }

        // Convertimos formas femeninas o neutras antes de buscar.
        $normalized = trim($statusName);
        $feminineToMasculine = [
            'Soltera' => 'Soltero',
            'Casada' => 'Casado',
        ];
        $normalized = $feminineToMasculine[$normalized] ?? $normalized;

        $status = MaritalStatus::where('name', 'ilike', $normalized)->first();

        return $status?->id;
    }

    /**
     * Validates an array of rows from the import file.
     * Returns each row with its validation status and errors.
     * Also validates company data consistency across all rows.
     */
    public function validateImportRows(array $rows): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'second_last_name' => 'nullable|string|max:255',
            'curp' => 'nullable|string|size:18',
            'rfc' => 'nullable|string|min:12|max:13',
            'nss' => 'nullable|string',
            'email' => 'nullable|email|max:255',
            'date_of_birth' => 'nullable|date',
            'contract_at' => 'nullable|date',
            'contracted' => 'nullable|boolean',
            'phone' => 'nullable|string|max:50',
            'marriage_record_number' => 'nullable|string|max:255',
            'marital_status' => 'nullable|string|max:255',
            'nationality' => 'nullable|string|max:255',
            'gender' => 'nullable|string|max:50',
        ];

        $messages = [
            'name.required' => 'Nombre es obligatorio',
            'last_name.required' => 'Primer apellido es obligatorio',
            'curp.size' => 'CURP debe tener 18 caracteres',
            'rfc.min' => 'RFC debe tener al menos 12 caracteres',
            'rfc.max' => 'RFC debe tener máximo 13 caracteres',
            'email.email' => 'Email no es válido',
            'date_of_birth.date' => 'Fecha de nacimiento no es válida',
            'contract_at.date' => 'Fecha de contrato no es válida',
            'contracted.boolean' => 'Contratado debe ser verdadero o falso',
        ];

        // Sin validación de empresa: los datos de empresa llegan en hoja separada, no en las filas

        $incomingCurps = array_filter(array_column($rows, 'curp'));
        $incomingRfcs = array_filter(array_column($rows, 'rfc'));
        $incomingEmails = array_filter(array_column($rows, 'email'));
        $incomingPhones = array_filter(array_column($rows, 'phone'));

        $existingCurps = $incomingCurps
            ? Collaborator::whereIn('curp', $incomingCurps)->pluck('curp')->flip()->toArray()
            : [];

        $existingRfcs = $incomingRfcs
            ? Collaborator::whereIn('rfc', $incomingRfcs)->pluck('rfc')->flip()->toArray()
            : [];

        $existingEmails = $incomingEmails
            ? Collaborator::whereIn('email', $incomingEmails)->pluck('email')->flip()->toArray()
            : [];

        $existingPhones = $incomingPhones
            ? Collaborator::whereIn('phone', $incomingPhones)->pluck('phone')->flip()->toArray()
            : [];

        $seenCurps = [];
        $seenRfcs = [];
        $seenEmails = [];
        $seenPhones = [];
        $seenIdentityKeys = [];

        $validated = [];

        foreach ($rows as $index => $row) {
            $validator = Validator::make($row, $rules, $messages);
            $errors = [];

            if ($validator->fails()) {
                $errors = $validator->errors()->all();
            }

            $curp = $row['curp'] ?? null;
            if ($curp) {
                if (isset($existingCurps[$curp])) {
                    $errors[] = 'CURP ya existe en el sistema';
                } elseif (isset($seenCurps[$curp])) {
                    $errors[] = 'CURP duplicado en el archivo';
                }
                $seenCurps[$curp] = true;
            }

            $rfc = $row['rfc'] ?? null;
            if ($rfc) {
                if (isset($existingRfcs[$rfc])) {
                    $errors[] = 'RFC ya existe en el sistema';
                } elseif (isset($seenRfcs[$rfc])) {
                    $errors[] = 'RFC duplicado en el archivo';
                }
                $seenRfcs[$rfc] = true;
            }

            $email = $row['email'] ?? null;
            if ($email) {
                if (isset($existingEmails[$email])) {
                    $errors[] = 'Email ya existe en el sistema';
                } elseif (isset($seenEmails[$email])) {
                    $errors[] = 'Email duplicado en el archivo';
                }
                $seenEmails[$email] = true;
            }

            $phone = $row['phone'] ?? null;
            if ($phone !== null && $phone !== '') {
                if (isset($existingPhones[$phone])) {
                    $errors[] = 'Teléfono ya existe en el sistema';
                } elseif (isset($seenPhones[$phone])) {
                    $errors[] = 'Teléfono duplicado en el archivo';
                }
                $seenPhones[$phone] = true;
            }

            $identityKey = $this->identityKey(
                $row['name'] ?? null,
                $row['last_name'] ?? null,
                $row['second_last_name'] ?? null,
                $row['date_of_birth'] ?? null
            );
            if ($identityKey !== null) {
                if ($this->existsCollaboratorWithIdentity(
                    trim((string) ($row['name'] ?? '')),
                    trim((string) ($row['last_name'] ?? '')),
                    trim((string) ($row['second_last_name'] ?? '')),
                    $row['date_of_birth'] ?? null
                )) {
                    $errors[] = 'Nombre completo y fecha de nacimiento ya existen en el sistema';
                } elseif (isset($seenIdentityKeys[$identityKey])) {
                    $errors[] = 'Nombre completo y fecha de nacimiento duplicados en el archivo';
                }
                $seenIdentityKeys[$identityKey] = true;
            }

            $validated[] = [
                'row_index' => $index,
                'data' => $row,
                'has_errors' => count($errors) > 0,
                'errors' => $errors,
            ];
        }

        return $validated;
    }

    /**
     * Batch import valid collaborator rows.
     * Company data arrives as a separate parameter (from the "Empresa" sheet).
     * Each collaborator row is inserted individually so failures are isolated.
     */
    public function batchImport(array $rows, array $companyData = []): array
    {
        $imported = [];
        $failed = [];

        foreach ($rows as $index => $row) {
            try {
                $resolvedRow = $row;

                // Resolver marital_status_id desde texto
                if (isset($row['marital_status']) && ! isset($row['marital_status_id'])) {
                    $resolvedRow['marital_status_id'] = $this->resolveMaritalStatusId((string) $row['marital_status']);
                }

                // Normalizar gender con sinónimos adicionales
                if (isset($resolvedRow['gender'])) {
                    $resolvedRow['gender'] = $this->normalizeGenderValue($resolvedRow['gender']);
                }

                $collaborator = DB::transaction(fn () => $this->create($resolvedRow));
                $imported[] = $collaborator;
            } catch (\Exception $e) {
                report($e);
                $failed[] = [
                    'row_index' => $index,
                    'data' => $row,
                    'error' => config('app.debug') ? $e->getMessage() : 'Error al crear el colaborador',
                ];
            }
        }

        // Actualizar empresa principal del tenant si vienen datos de empresa
        $companyPayload = array_filter(
            $companyData,
            fn ($v) => $v !== null && $v !== ''
        );

        if (! empty($companyPayload) && ! empty($imported)) {
            try {
                $mainCompany = Company::query()->main()->first();
                if ($mainCompany) {
                    $mainCompany->update($companyPayload);
                }
            } catch (\Exception $e) {
                report($e);
                // No bloqueamos el resultado del import por esto
            }
        }

        return [
            'imported' => $imported,
            'imported_count' => count($imported),
            'failed' => $failed,
            'failed_count' => count($failed),
        ];
    }

    /**
     * Build a unique key for identity duplicate check (name + last_name + second_last_name + date_of_birth).
     * Returns null if name or last_name is empty.
     */
    private function identityKey(?string $name, ?string $lastName, ?string $secondLastName, mixed $dateOfBirth): ?string
    {
        $name = $name !== null ? trim($name) : '';
        $lastName = $lastName !== null ? trim($lastName) : '';
        if ($name === '' || $lastName === '') {
            return null;
        }
        $secondLastName = $secondLastName !== null ? trim($secondLastName) : '';
        $date = $dateOfBirth instanceof CarbonInterface
            ? $dateOfBirth->format('Y-m-d')
            : (is_string($dateOfBirth) && $dateOfBirth !== '' ? $dateOfBirth : '');

        return implode('|', [$name, $lastName, $secondLastName, $date]);
    }

    /**
     * Colaboradores por nombre y fecha de nacimiento en tabla collaborator.
     */
    private function existsCollaboratorWithIdentity(string $name, string $lastName, string $secondLastName, mixed $dateOfBirth): bool
    {
        $query = Collaborator::query()
            ->where('name', $name)
            ->where('last_name', $lastName);

        if ($secondLastName === '') {
            $query->where(function ($q) {
                $q->whereNull('second_last_name')->orWhere('second_last_name', '');
            });
        } else {
            $query->where('second_last_name', $secondLastName);
        }

        $fechasExistentes = $query->pluck('date_of_birth');

        $fechaRow = null;
        if ($dateOfBirth !== null && $dateOfBirth !== '') {
            $fechaRow = $dateOfBirth instanceof CarbonInterface
                ? $dateOfBirth->format('Y-m-d')
                : (string) $dateOfBirth;
        }

        foreach ($fechasExistentes as $fecha) {
            $fechaStr = $fecha instanceof CarbonInterface ? $fecha->format('Y-m-d') : ($fecha ?? null);
            if ($fechaRow === $fechaStr) {
                return true;
            }
        }

        return false;
    }
}
