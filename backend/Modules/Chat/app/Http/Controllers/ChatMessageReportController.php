<?php

namespace Modules\Chat\Http\Controllers;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Modules\Chat\Models\ChatMessageReport;
use Modules\Chat\Services\ChatService;
use QCH\Core\Http\Controllers\Controller;

/**
 * Moderation review of reported messages (§13.5 pasos 7-8).
 *
 * Read-only Orion resource (index/search/show) gated by
 * read.chat_message_report; reports are CREATED via
 * POST /chat-message/{id}/report (member-gated) and resolved either by
 * hiding the message (→ reviewed) or by the dismiss action below.
 */
class ChatMessageReportController extends Controller
{
    protected $model = ChatMessageReport::class;

    protected $keyForPermission = 'chat_message_report';

    public function includes(): array
    {
        return ['message', 'message.channel', 'message.user', 'reporterUser'];
    }

    public function filterableBy(): array
    {
        return ['status', 'chat_message_id', 'reporter_user_id'];
    }

    public function sortableBy(): array
    {
        return ['created_at', 'status'];
    }

    /**
     * POST /chat-message-report/{id}/dismiss
     * Dismiss a pending report — the message stays visible (§13.5 paso 8).
     */
    public function dismiss(string $id): JsonResponse
    {
        $this->authorize('update.chat_message_report', ChatMessageReport::class);

        $report = ChatMessageReport::findOrFail($id);

        try {
            $dismissed = app(ChatService::class)->dismissReport($report);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $dismissed]);
    }
}
