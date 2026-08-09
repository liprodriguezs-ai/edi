<?php

namespace Modules\Chat\Http\Controllers;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Chat\Exceptions\ChatAccessDeniedException;
use Modules\Chat\Exceptions\ChatRateLimitException;
use Modules\Chat\Models\ChatChannel;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Services\ChatService;
use Orion\Http\Requests\Request as OrionRequest;
use QCH\Core\Http\Controllers\Controller;

class ChatMessageController extends Controller
{
    public const HIDDEN_BODY_PLACEHOLDER = '[Mensaje ocultado por moderación]';

    protected $model = ChatMessage::class;

    protected $keyForPermission = 'chat_message';

    public function includes(): array
    {
        return ['channel', 'user', 'replyTo', 'reactions', 'media'];
    }

    public function filterableBy(): array
    {
        return ['chat_channel_id', 'user_id', 'hidden', 'reply_to_id'];
    }

    public function sortableBy(): array
    {
        return ['created_at'];
    }

    public function searchableBy(): array
    {
        return ['body'];
    }

    /**
     * Store goes through ChatService::postMessage so access, channel write-mode,
     * threading, rate limit, HTML sanitization and notifications are ALWAYS
     * enforced. The author is the authenticated user — user_id/hidden/body_html
     * in the payload are never trusted (body_html is sanitized server-side).
     */
    protected function performStore(OrionRequest $request, Model $entity, array $attributes): void
    {
        $validated = $request->validated();

        $channel = ChatChannel::findOrFail($validated['chat_channel_id']);

        // Pair uploaded files with their (optional) titles by index.
        $attachments = [];
        $titles = (array) $request->input('attachment_titles', []);
        foreach ((array) $request->file('attachments', []) as $index => $file) {
            $attachments[] = ['file' => $file, 'title' => $titles[$index] ?? null];
        }

        try {
            $message = app(ChatService::class)->postMessage(
                $channel,
                (string) $request->user()->id,
                $validated['body'] ?? '',
                $validated['reply_to_id'] ?? null,
                $validated['body_html'] ?? null,
                $attachments,
            );
        } catch (ChatRateLimitException $e) {
            abort(429, $e->getMessage());
        } catch (ChatAccessDeniedException $e) {
            abort(403, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $entity->setRawAttributes($message->getAttributes());
        $entity->syncOriginal();
        $entity->exists = true;
    }

    /**
     * §13.2 'Permisos por canal, no globales': index/search only return messages
     * from channels the caller can ACCESS (by target_type), unless the caller is
     * a moderator. Membership is not required.
     */
    protected function buildIndexFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        return $this->scopeToAccessibleChannels(parent::buildIndexFetchQuery($request, $requestedRelations));
    }

    /**
     * Show of a message in a channel the caller cannot access → 404.
     */
    protected function buildShowFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        return $this->scopeToAccessibleChannels(parent::buildShowFetchQuery($request, $requestedRelations));
    }

    /**
     * Hidden message bodies are redacted for non-moderators — the content is
     * preserved for audit/moderation only (§13.4.4).
     */
    protected function afterIndex(OrionRequest $request, $entities)
    {
        $this->redactHiddenBodies($entities instanceof Paginator ? $entities->items() : $entities);

        return null;
    }

    protected function afterShow(OrionRequest $request, Model $entity)
    {
        $this->redactHiddenBodies([$entity]);

        return null;
    }

    /**
     * POST /chat-message/{id}/hide
     * Moderator soft-hides a message (never deleted). Resolves pending reports.
     */
    public function hide(string $id): JsonResponse
    {
        $this->authorize('update.chat_message', ChatMessage::class);

        $message = ChatMessage::findOrFail($id);

        $service = app(ChatService::class);
        $hidden = $service->hideMessage($message);

        return response()->json(['data' => $hidden]);
    }

    /**
     * POST /chat-message/{id}/unhide
     * Moderator reverses a hide. Moderation never rewrites bodies — hide/unhide
     * are the only moderation verbs on message content (§13.4.4).
     */
    public function unhide(string $id): JsonResponse
    {
        $this->authorize('update.chat_message', ChatMessage::class);

        $message = ChatMessage::findOrFail($id);

        $restored = app(ChatService::class)->unhideMessage($message);

        return response()->json(['data' => $restored]);
    }

    /**
     * POST /chat-message/{id}/edit
     * Author edits their OWN message within the edit window; edited_at is
     * stamped server-side (§13.4.6). No update.chat_message ability required —
     * ownership is the gate, so the Orion update route stays unregistered.
     */
    public function edit(string $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $message = ChatMessage::findOrFail($id);

        try {
            $updated = app(ChatService::class)->editOwnMessage($message, (string) $request->user()->id, $validated['body']);
        } catch (ChatAccessDeniedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $updated]);
    }

    /**
     * POST /chat-message/{id}/react
     * Add an emoji reaction to a message.
     */
    public function react(string $id, Request $request): JsonResponse
    {
        $message = ChatMessage::findOrFail($id);
        $userId = (string) $request->user()->id;
        $emoji = $request->input('emoji', '👍');

        try {
            $reaction = app(ChatService::class)->react($message, $userId, $emoji);
        } catch (ChatAccessDeniedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $reaction]);
    }

    /**
     * DELETE /chat-message/{id}/react
     * Remove the caller's emoji reaction from a message.
     */
    public function unreact(string $id, Request $request): JsonResponse
    {
        $message = ChatMessage::findOrFail($id);
        $userId = (string) $request->user()->id;
        $emoji = $request->input('emoji', '👍');

        try {
            app(ChatService::class)->unreact($message, $userId, $emoji);
        } catch (ChatAccessDeniedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['message' => 'Reacción eliminada.']);
    }

    /**
     * POST /chat-message/{id}/report
     * Report a message for moderation review (members only, one per message).
     */
    public function report(string $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $message = ChatMessage::findOrFail($id);
        $userId = (string) $request->user()->id;

        try {
            $reportRecord = app(ChatService::class)->report($message, $userId, $validated['reason']);
        } catch (ChatAccessDeniedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $reportRecord], 201);
    }

    private function scopeToAccessibleChannels(Builder $query): Builder
    {
        if ($this->callerCanModerate()) {
            return $query;
        }

        $userId = (string) auth()->guard('sanctum')->id();

        return $query->whereIn(
            'chat_channel_id',
            app(ChatService::class)->accessibleChannelIds($userId),
        );
    }

    /**
     * @param  iterable<int, mixed>  $messages
     */
    private function redactHiddenBodies(iterable $messages): void
    {
        if ($this->callerCanModerate()) {
            return;
        }

        foreach ($messages as $message) {
            if (! $message instanceof ChatMessage) {
                continue;
            }

            $this->redactIfHidden($message);

            // replyTo is an allow-listed include and a self-reference to ChatMessage,
            // so a hidden parent's body would otherwise leak through it.
            if ($message->relationLoaded('replyTo') && $message->replyTo instanceof ChatMessage) {
                $this->redactIfHidden($message->replyTo);
            }
        }
    }

    private function redactIfHidden(ChatMessage $message): void
    {
        if ($message->hidden) {
            $message->body = self::HIDDEN_BODY_PLACEHOLDER;
            $message->body_html = null;
        }
    }

    private function callerCanModerate(): bool
    {
        $user = auth()->guard('sanctum')->user();

        return $user !== null && ($user->tokenCan('update.chat_message') || $user->tokenCan('*'));
    }
}
