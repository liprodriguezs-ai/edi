<?php

namespace Modules\FollowUp\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Modules\FollowUp\Exceptions\FollowUpDomainException;
use Modules\FollowUp\Http\Requests\FollowUpCancelRequest;
use Modules\FollowUp\Http\Requests\FollowUpCreateRequest;
use Modules\FollowUp\Http\Requests\FollowUpUpdateRequest;
use Modules\FollowUp\Models\FollowUp;
use Modules\FollowUp\Models\FollowUpStatus;
use Modules\FollowUp\Services\FollowUpCreationService;
use Modules\FollowUp\Services\FollowUpLifecycleService;
use Modules\Screening\Models\Screening;
use Modules\Screening\Models\ScreeningStatus;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

/**
 * Orion serves index/show/store/update (design §4); manual create requires
 * an existing completed screening of the child (REQ-FUP-006). Transitions
 * (start/complete/cancel) are custom routes delegating to
 * FollowUpLifecycleService.
 */
class FollowUpController extends Controller
{
    protected $model = FollowUp::class;

    protected $keyForPermission = 'follow_up';

    public function includes(): array
    {
        return [
            'child', 'screening', 'follow_up_type', 'follow_up_status',
            'referral_specialty', 'closed_by_screening',
            'referrals', 'referrals.referral_specialty',
        ];
    }

    public function filterableBy(): array
    {
        return ['child_id', 'follow_up_status_id', 'follow_up_type_id', 'due_date'];
    }

    /**
     * Orion's sortableBy() defaults to an EMPTY list, so leaving it undeclared
     * rejects EVERY sort with "The <field> field is not whitelisted". The list
     * sorts by due_date ascending on first load -- surfacing an overdue follow-up
     * is the point of the screen -- so the request came back 422 and the screen
     * rendered zero rows with nothing shown to the user.
     */
    public function sortableBy(): array
    {
        return ['due_date', 'created_at'];
    }

    public function alwaysIncludes(): array
    {
        return $this->includes();
    }

    /**
     * REQ-FUP-006 -- manual create. child_id is derived from the screening,
     * never accepted from the client. follow_up_status_id is service-derived
     * (D7); any client-sent status/closed_by_screening_id/completed_at is
     * simply absent from the whitelisted validated() payload.
     */
    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(FollowUpCreateRequest::class)->validated();
        $screening = Screening::findOrFail($validated['screening_id']);

        if ($screening->screening_status_id !== ScreeningStatus::COMPLETED) {
            throw FollowUpDomainException::screeningNotEligible();
        }

        if (FollowUp::where('screening_id', $screening->id)->where('follow_up_type_id', $validated['follow_up_type_id'])->exists()) {
            throw FollowUpDomainException::duplicate();
        }

        $entity->forceFill([
            'child_id' => $screening->child_id,
            'screening_id' => $screening->id,
            'follow_up_type_id' => $validated['follow_up_type_id'],
            'follow_up_status_id' => FollowUpCreationService::deriveStatusId($validated['due_date']),
            'due_date' => $validated['due_date'],
            'referral_specialty_id' => $validated['referral_specialty_id'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);
        $entity->save();
    }

    /**
     * REQ-FUP-006 -- update allows only due_date/notes/referral_specialty_id
     * while open; terminal (completed/cancelled) freezes with 409.
     */
    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        if (in_array($entity->follow_up_status_id, [FollowUpStatus::COMPLETED, FollowUpStatus::CANCELLED], true)) {
            throw FollowUpDomainException::frozen();
        }

        $validated = app(FollowUpUpdateRequest::class)->validated();
        $entity->fill($validated);

        // D7: due_date write-time derivation applies only over the
        // {pending, overdue} base state -- an in_progress follow-up must
        // never be silently flipped back by a due_date edit.
        if ($entity->isDirty('due_date') && in_array($entity->follow_up_status_id, [FollowUpStatus::PENDING, FollowUpStatus::OVERDUE], true)) {
            $entity->forceFill(['follow_up_status_id' => FollowUpCreationService::deriveStatusId($entity->due_date)]);
        }

        $entity->save();
    }

    /**
     * POST /api/follow_up/{followUp}/start — REQ-FUP-006.
     */
    public function start(FollowUp $followUp, FollowUpLifecycleService $service): JsonResponse
    {
        $updated = $service->start($followUp)->load($this->includes());

        return response()->json(['data' => $updated]);
    }

    /**
     * POST /api/follow_up/{followUp}/complete — REQ-FUP-006.
     */
    public function complete(FollowUp $followUp, FollowUpLifecycleService $service): JsonResponse
    {
        $updated = $service->complete($followUp)->load($this->includes());

        return response()->json(['data' => $updated]);
    }

    /**
     * POST /api/follow_up/{followUp}/cancel — REQ-FUP-006.
     */
    public function cancel(FollowUp $followUp, FollowUpCancelRequest $request, FollowUpLifecycleService $service): JsonResponse
    {
        $updated = $service->cancel($followUp, $request->validated())->load($this->includes());

        return response()->json(['data' => $updated]);
    }
}
