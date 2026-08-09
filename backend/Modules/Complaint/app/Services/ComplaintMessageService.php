<?php

namespace Modules\Complaint\Services;

use App\Models\User;
use App\Notifications\Concerns\NotifiesResidents;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Complaint\Models\Complaint;
use Modules\Complaint\Models\ComplaintMessage;
use Modules\Complaint\Support\ComplaintAccess;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Split from ComplaintService to keep each service under the house line
 * budget (mirrors the old TicketMessageService split) — owns the follow-up
 * message thread (spec "Follow-up Message Thread").
 *
 * NOTE: NotifiesResidents::notifyEvent() already wraps its dispatch in
 * DB::afterCommit() internally (see notifyUsers()) — the notifyEvent() call
 * below runs safely from inside the DB::transaction() closure without needing
 * (or risking double-wrapping with) an explicit afterCommit() here.
 */
class ComplaintMessageService
{
    use NotifiesResidents;

    /**
     * Post a follow-up message to a complaint's thread.
     *
     * Visibility (spec "Follow-up Message Thread"): only the reporter, the
     * assignee, or a non-excluded manager-role user may view/post.
     *
     * @throws HttpException
     */
    public function postMessage(Complaint $complaint, string $body, User $author, ?UploadedFile $attachment = null): ComplaintMessage
    {
        $this->assertVisible($complaint, $author);

        return DB::transaction(function () use ($complaint, $body, $author, $attachment): ComplaintMessage {
            $message = ComplaintMessage::create([
                'complaint_id' => $complaint->id,
                'user_id' => $author->id,
                'body' => $body,
            ]);

            // NOTE (accepted tradeoff, mirrors ComplaintService::register): media is
            // attached inside the transaction; compensated by the scheduled
            // media-library:clean job on rollback.
            if ($attachment instanceof UploadedFile) {
                $message->addMedia($attachment)->toMediaCollection('attachment');
            }

            $recipients = $this->otherPartyUserIds($complaint, $author);

            if ($recipients !== []) {
                $this->notifyEvent(
                    'complaint_message',
                    $recipients,
                    ['case_number' => $complaint->case_number, 'title' => $complaint->title],
                    'Nuevo mensaje en una incidencia',
                    "Nuevo mensaje en la incidencia {$complaint->case_number}.",
                    ['click_action' => '/complaint/'.$complaint->id],
                );
            }

            return $message;
        });
    }

    /**
     * Assert the caller can see this complaint's thread — the reporter, the
     * assignee, or a non-excluded manager-role user (§6 unit-linkage exclusion).
     *
     * @throws HttpException
     */
    public function assertVisible(Complaint $complaint, User $user): void
    {
        if ($user->id === $complaint->reporter_user_id || $user->id === $complaint->assigned_user_id) {
            return;
        }

        if (! $user->hasAnyRole(ComplaintAccess::managerRoleKeys())) {
            abort(403, 'No tienes acceso a esta incidencia');
        }

        if (in_array($user->id, ComplaintAccess::excludedUserIds($complaint->against_unit_id), true)) {
            abort(403, 'No puedes acceder a una incidencia vinculada a tu propia unidad');
        }
    }

    /**
     * The "other party" of a two-sided thread (design §8): when the reporter
     * posts, notify the assignee; when the assignee or a manager posts, notify
     * the reporter. Never includes the author themselves.
     *
     * @return array<int, string>
     */
    private function otherPartyUserIds(Complaint $complaint, User $author): array
    {
        if ($author->id === $complaint->reporter_user_id) {
            return array_values(array_filter([$complaint->assigned_user_id]));
        }

        return array_values(array_filter([$complaint->reporter_user_id]));
    }
}
