<?php

declare(strict_types=1);

namespace Modules\FollowUp\Services;

use App\Notifications\Concerns\DispatchesNotifications;
use App\Notifications\NotificationCategory;
use Illuminate\Support\Facades\DB;
use Modules\FollowUp\Exceptions\FollowUpDomainException;
use Modules\FollowUp\Models\FollowUp;
use Modules\FollowUp\Models\FollowUpStatus;
use Modules\FollowUp\Models\FollowUpType;
use Modules\FollowUp\Models\Referral;

/**
 * Type gate + in_progress side effect + notification (design D8/D11,
 * REQ-FUP-007/008). TENANT CONTEXT REQUIRED for the notification (see
 * DispatchesNotifications) -- always true here since every caller runs
 * behind initializeTenancy.
 */
final class ReferralService
{
    use DispatchesNotifications;

    /**
     * Mutates and saves the Orion-provided $entity in place (same
     * collaboration style as ConfirmatoryTestController::performStore) --
     * Orion instantiates `new Referral`, and every hook downstream of
     * performStore keeps operating on that exact object.
     *
     * @param  array{follow_up_id: string, referral_specialty_id: string, referred_at: string, institution?: ?string, outcome_notes?: ?string}  $data
     */
    public function create(Referral $entity, array $data): void
    {
        DB::transaction(function () use ($entity, $data): void {
            /** @var FollowUp $followUp */
            $followUp = FollowUp::query()->whereKey($data['follow_up_id'])->lockForUpdate()->firstOrFail();

            if (in_array($followUp->follow_up_status_id, [FollowUpStatus::COMPLETED, FollowUpStatus::CANCELLED], true)) {
                throw FollowUpDomainException::frozen();
            }

            if ($followUp->follow_up_type_id !== FollowUpType::SPECIALIST_REFERRAL) {
                throw FollowUpDomainException::typeMismatch();
            }

            $entity->forceFill([
                'follow_up_id' => $followUp->id,
                'referral_specialty_id' => $data['referral_specialty_id'],
                'referred_at' => $data['referred_at'],
                'institution' => $data['institution'] ?? null,
                'outcome_notes' => $data['outcome_notes'] ?? null,
            ]);
            $entity->save();

            // REQ-FUP-007 -- creating a referral marks progress; completion
            // stays manual (D11: a human confirms the outcome).
            if (in_array($followUp->follow_up_status_id, [FollowUpStatus::PENDING, FollowUpStatus::OVERDUE], true)) {
                $followUp->forceFill(['follow_up_status_id' => FollowUpStatus::IN_PROGRESS])->save();
            }

            $this->notifyReferralCreated($followUp, $entity);
        });
    }

    /**
     * REQ-FUP-008 -- reuses the existing notification engine, no new
     * channel. notifyEvent() defers via DB::afterCommit, so calling it
     * inside this open transaction is safe.
     */
    private function notifyReferralCreated(FollowUp $followUp, Referral $referral): void
    {
        $adminIds = $this->userIdsWithRole('administrator');

        if ($adminIds === []) {
            return;
        }

        $followUp->loadMissing('child');
        $referral->loadMissing('referral_specialty');

        $childName = trim((string) $followUp->child?->first_name.' '.(string) $followUp->child?->last_name_paternal);
        $specialtyName = (string) ($referral->referral_specialty?->name ?? '');

        $this->notifyEvent(
            'follow_up.referral_created',
            $adminIds,
            ['child' => $childName, 'specialty' => $specialtyName],
            'Nueva referencia a especialista',
            'Se creó una referencia a {specialty} para {child}.',
            ['category' => NotificationCategory::FOLLOW_UP],
        );
    }
}
