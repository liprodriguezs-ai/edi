<?php

namespace Modules\Housing\Services;

use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\NotificationCategory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitUser;

class UnitUserService
{
    use NotifiesResidents;

    /**
     * Create a new unit_user assignment enforcing business invariants:
     *  - At most one active billing_owner per unit.
     *  - At most one active legal_representative per unit.
     *  - Assigning a renter requires at least one active owner.
     */
    public function create(array $data): UnitUser
    {
        return DB::transaction(function () use ($data): UnitUser {
            $this->assertInvariants($data);

            if (empty($data['end_date']) && ! empty($data['is_billing_owner'])) {
                $this->unsetActiveBillingOwner($data['unit_id']);
            }

            if (empty($data['end_date']) && ! empty($data['is_legal_representative'])) {
                $this->unsetActiveLegalRepresentative($data['unit_id']);
            }

            $unitUser = UnitUser::create($data);

            // §6.7: notify the person they were associated to a unit (SMS + email).
            // For owners, this also serves as the owner-change notification to the new owner.
            $this->notifyUsers(
                [$unitUser->user_id],
                'Asignación a unidad',
                'Has sido asociado a una unidad del condominio como '.$this->relationshipLabel($unitUser->unit_relationship_id).'.',
                [
                    'category' => NotificationCategory::SYSTEM,
                    'click_action' => '/my-unit',
                    'unit_id' => $unitUser->unit_id,
                    'unit_user_id' => $unitUser->id,
                ],
                channels: ['log', 'sms', 'resend_email']
            );

            return $unitUser;
        });
    }

    /**
     * Human-readable Spanish label for a unit_relationship id (best-effort).
     */
    private function relationshipLabel(?string $relationshipId): string
    {
        return match ($relationshipId) {
            UnitRelationship::OWNER => 'propietario',
            UnitRelationship::RENTER => 'inquilino',
            default => 'residente',
        };
    }

    /**
     * Update an existing unit_user assignment enforcing business invariants.
     */
    public function update(UnitUser $unitUser, array $data): UnitUser
    {
        return DB::transaction(function () use ($unitUser, $data): UnitUser {
            // Merge with existing values so invariant checks see the final state
            $merged = array_merge($unitUser->toArray(), $data);
            $this->assertInvariants($merged, excludeId: $unitUser->id);

            if (! empty($data['is_billing_owner']) && ! $unitUser->is_billing_owner) {
                $this->unsetActiveBillingOwner($unitUser->unit_id, excludeId: $unitUser->id);
            }

            if (! empty($data['is_legal_representative']) && ! $unitUser->is_legal_representative) {
                $this->unsetActiveLegalRepresentative($unitUser->unit_id, excludeId: $unitUser->id);
            }

            $unitUser->update($data);

            return $unitUser->fresh();
        });
    }

    /**
     * §6.3.5 / §6.5 r6 — owner change in ONE transaction: closes every ACTIVE
     * owner association at change_date - 1 day, creates the new owner as titular
     * de pago starting at change_date, and notifies previous AND new owners
     * (Email + SMS, §6.7). Closed rows are kept as history (§6.3.4 r5).
     *
     * @param  array{user_id: string, change_date?: string|null, notes?: string|null}  $data
     *
     * @throws \InvalidArgumentException when the person is already the active owner
     */
    public function changeOwner(Unit $unit, array $data): UnitUser
    {
        $changeDate = isset($data['change_date'])
            ? CarbonImmutable::parse($data['change_date'])
            : CarbonImmutable::today();

        return DB::transaction(function () use ($unit, $data, $changeDate): UnitUser {
            $activeOwners = UnitUser::where('unit_id', $unit->id)
                ->where('unit_relationship_id', UnitRelationship::OWNER)
                ->whereNull('end_date')
                ->lockForUpdate()
                ->get();

            if ($activeOwners->contains('user_id', $data['user_id'])) {
                throw new \InvalidArgumentException('La persona seleccionada ya es propietaria activa de esta unidad.');
            }

            $transfersLegalRepresentative = $activeOwners->contains(
                static fn (UnitUser $association): bool => $association->is_legal_representative
            );

            foreach ($activeOwners as $previous) {
                $previous->update(['end_date' => $changeDate->subDay()]);
            }

            // The new owner takes over billing: clear any OTHER active holder
            // (e.g. a billing contact) before flagging the new association.
            $this->unsetActiveBillingOwner($unit->id);

            $newOwner = UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $data['user_id'],
                'unit_relationship_id' => UnitRelationship::OWNER,
                'is_billing_owner' => true,
                'is_legal_representative' => $transfersLegalRepresentative,
                'start_date' => $changeDate,
                'notes' => $data['notes'] ?? null,
            ]);

            $unitLabel = $unit->full_identifier;

            $previousUserIds = $activeOwners->pluck('user_id')->unique()->values()->all();
            if ($previousUserIds !== []) {
                $this->notifyUsers(
                    $previousUserIds,
                    'Cambio de propietario',
                    'Tu asociación como propietario de '.$unitLabel.' se cerró el '.$changeDate->subDay()->format('d/m/Y').'. Tu histórico se conserva.',
                    [
                        'category' => NotificationCategory::SYSTEM,
                        'click_action' => '/my-unit',
                        'unit_id' => $unit->id,
                    ],
                    channels: ['log', 'resend_email', 'sms']
                );
            }

            $this->notifyUsers(
                [$newOwner->user_id],
                'Cambio de propietario',
                'Eres el nuevo propietario y titular de pago de '.$unitLabel.' a partir del '.$changeDate->format('d/m/Y').'.',
                [
                    'category' => NotificationCategory::SYSTEM,
                    'click_action' => '/my-unit',
                    'unit_id' => $unit->id,
                    'unit_user_id' => $newOwner->id,
                ],
                channels: ['log', 'resend_email', 'sms']
            );

            return $newOwner;
        });
    }

    /**
     * Validate domain invariants before persisting.
     */
    private function assertInvariants(array $data, ?string $excludeId = null): void
    {
        $unitId = $data['unit_id'];
        $relationshipId = $data['unit_relationship_id'];
        $endDate = $data['end_date'] ?? null;
        $isActive = $endDate === null;

        // Only enforce active-record invariants (end_date null = active)
        if (! $isActive) {
            return;
        }

        // Renter requires at least one active owner already on the unit.
        if ($relationshipId === UnitRelationship::RENTER) {
            $ownerRelId = UnitRelationship::OWNER;
            $ownerExists = UnitUser::where('unit_id', $unitId)
                ->where('unit_relationship_id', $ownerRelId)
                ->whereNull('end_date')
                ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
                ->exists();

            if (! $ownerExists) {
                throw ValidationException::withMessages([
                    'unit_relationship_id' => ['No se puede asignar un inquilino sin que la unidad tenga al menos un propietario activo.'],
                ]);
            }
        }
    }

    /**
     * Clear is_billing_owner flag on all other active assignments for the unit.
     */
    private function unsetActiveBillingOwner(string $unitId, ?string $excludeId = null): void
    {
        UnitUser::where('unit_id', $unitId)
            ->where('is_billing_owner', true)
            ->whereNull('end_date')
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->update(['is_billing_owner' => false]);
    }

    /**
     * Clear is_legal_representative flag on all other active assignments for the unit.
     */
    private function unsetActiveLegalRepresentative(string $unitId, ?string $excludeId = null): void
    {
        UnitUser::where('unit_id', $unitId)
            ->where('is_legal_representative', true)
            ->whereNull('end_date')
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->update(['is_legal_representative' => false]);
    }
}
