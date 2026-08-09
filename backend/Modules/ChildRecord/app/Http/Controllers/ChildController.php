<?php

namespace Modules\ChildRecord\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Modules\ChildRecord\Http\Requests\ChildCreateRequest;
use Modules\ChildRecord\Http\Requests\ChildUpdateRequest;
use Modules\ChildRecord\Models\Child;
use Modules\ChildRecord\Models\Sex;
use Modules\ChildRecord\Rules\Curp;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

class ChildController extends Controller
{
    protected $model = Child::class;

    protected $keyForPermission = 'child';

    public function includes(): array
    {
        return ['sex', 'health_center', 'birth_record'];
    }

    public function sortableBy(): array
    {
        return ['first_name', 'last_name_paternal', 'birth_date', 'created_at'];
    }

    /**
     * REQ-XC-006 — list search is by name/curp only; no eager catalog dumps.
     */
    public function searchableBy(): array
    {
        return ['first_name', 'last_name_paternal', 'last_name_maternal', 'curp', 'external_id'];
    }

    public function filterableBy(): array
    {
        return ['health_center_id', 'sex_id'];
    }

    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(ChildCreateRequest::class)->validated();
        $entity->fill($validated);
        $entity->curp_mismatch = $this->computeCurpMismatch($validated);
        $this->saveOrConflict($entity);
    }

    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(ChildUpdateRequest::class)->validated();
        $entity->fill($validated);

        // Recompute curp_mismatch whenever curp, birth_date, or sex_id change.
        $merged = [
            'curp' => $entity->curp,
            'birth_date' => $entity->birth_date?->format('Y-m-d'),
            'sex_id' => $entity->sex_id,
        ];
        $entity->curp_mismatch = $this->computeCurpMismatch($merged);
        $this->saveOrConflict($entity);
    }

    /**
     * REQ-CH-003 — the FormRequest's per-tenant `Rule::unique` pre-checks on
     * curp/external_id run against the RAW (still un-normalized) curp, since
     * Orion's request pipeline in this project validates before
     * prepareForValidation() can uppercase it. A case-different duplicate
     * slips that pre-check but is still caught here by the DB partial unique
     * indexes — mapped to a friendly 422 instead of a raw 500.
     */
    private function saveOrConflict(Model $entity): void
    {
        try {
            $entity->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'curp' => ['Ya existe un niño con este CURP o external_id en tu organización.'],
            ]);
        }
    }

    /**
     * REQ-CH-002 / D-16 — CURP cross-consistency against birth_date/sex_id is
     * a non-blocking WARNING: compute the flag, never fail the request.
     *
     * @param  array<string, mixed>  $data
     */
    private function computeCurpMismatch(array $data): bool
    {
        $curp = $data['curp'] ?? null;
        $birthDate = $data['birth_date'] ?? null;
        $sexId = $data['sex_id'] ?? null;

        if ($curp === null || $birthDate === null || $sexId === null) {
            return false;
        }

        $sexKey = match ($sexId) {
            Sex::MALE => 'male',
            Sex::FEMALE => 'female',
            default => null,
        };

        if ($sexKey === null) {
            return false;
        }

        return ! Curp::isConsistentWith($curp, (string) $birthDate, $sexKey);
    }
}
