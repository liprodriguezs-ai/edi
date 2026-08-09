<?php

declare(strict_types=1);

namespace Modules\ChildRecord\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\ChildRecord\Models\ChildCaregiver;

/**
 * REQ-CH-006 — keeps the invariant "a child with links has exactly one
 * primary caregiver": the first link for a child auto-becomes primary;
 * promoting a new primary demotes the current one in the SAME transaction
 * (demote-then-promote ordering, design D11/§3.3) so the DB partial unique
 * index (child_caregiver_primary_unique) never trips on a legal transition;
 * deleting the primary link while others exist is blocked until the client
 * designates a new primary.
 */
class ChildCaregiverService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): ChildCaregiver
    {
        return DB::transaction(function () use ($attributes): ChildCaregiver {
            $hasExistingLinks = ChildCaregiver::where('child_id', $attributes['child_id'])->exists();

            // First link for a child auto-becomes primary regardless of input.
            $isPrimary = $hasExistingLinks ? (bool) ($attributes['is_primary'] ?? false) : true;

            if ($isPrimary) {
                $this->demoteCurrentPrimary($attributes['child_id']);
            }

            try {
                return ChildCaregiver::create([...$attributes, 'is_primary' => $isPrimary]);
            } catch (UniqueConstraintViolationException $e) {
                throw $this->conflictException($e);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(ChildCaregiver $link, array $attributes): ChildCaregiver
    {
        return DB::transaction(function () use ($link, $attributes): ChildCaregiver {
            $promotingToPrimary = array_key_exists('is_primary', $attributes) && (bool) $attributes['is_primary'];

            if ($promotingToPrimary) {
                $this->demoteCurrentPrimary($link->child_id, exceptId: $link->id);
            }

            $link->fill($attributes);

            try {
                $link->save();
            } catch (UniqueConstraintViolationException $e) {
                throw $this->conflictException($e);
            }

            return $link->refresh();
        });
    }

    public function delete(ChildCaregiver $link): void
    {
        DB::transaction(function () use ($link): void {
            if ($link->is_primary) {
                $otherLinksExist = ChildCaregiver::where('child_id', $link->child_id)
                    ->where('id', '!=', $link->id)
                    ->exists();

                if ($otherLinksExist) {
                    throw ValidationException::withMessages([
                        'is_primary' => ['Debes designar un nuevo cuidador primario antes de eliminar este vínculo.'],
                    ]);
                }
            }

            $link->delete();
        });
    }

    private function demoteCurrentPrimary(string $childId, ?string $exceptId = null): void
    {
        ChildCaregiver::where('child_id', $childId)
            ->where('is_primary', true)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->update(['is_primary' => false]);
    }

    private function conflictException(UniqueConstraintViolationException $e): ValidationException
    {
        return ValidationException::withMessages([
            'is_primary' => ['Ya existe un cuidador primario para este niño. Vuelve a intentarlo.'],
        ]);
    }
}
