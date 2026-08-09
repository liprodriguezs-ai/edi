<?php

namespace Modules\Chat\Http\Controllers;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Modules\Chat\Exceptions\ChatAccessDeniedException;
use Modules\Chat\Models\ChatChannel;
use Modules\Chat\Services\ChatService;
use Orion\Http\Requests\Request as OrionRequest;
use QCH\Core\Http\Controllers\Controller;

class ChatChannelController extends Controller
{
    protected $model = ChatChannel::class;

    protected $keyForPermission = 'chat_channel';

    public function includes(): array
    {
        return ['channelType', 'members', 'members.user'];
    }

    public function filterableBy(): array
    {
        return ['chat_channel_type_id', 'is_system', 'is_archived', 'write_target_type'];
    }

    public function sortableBy(): array
    {
        return ['name', 'created_at'];
    }

    public function searchableBy(): array
    {
        return ['name', 'description'];
    }

    /**
     * §13.2: a caller only lists/shows the channels they can ACCESS (by
     * target_type) — open channels for residents, the board channel for the
     * board, direct channels they belong to. Channel managers see everything.
     */
    protected function buildIndexFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        return $this->scopeToAccessibleChannels(parent::buildIndexFetchQuery($request, $requestedRelations));
    }

    protected function buildShowFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        return $this->scopeToAccessibleChannels(parent::buildShowFetchQuery($request, $requestedRelations));
    }

    /**
     * Stamp the computed per-user can_read / can_write flags so the frontend can
     * render the composer (write) or a read-only banner without re-deriving the
     * access rules (§13.4).
     */
    protected function afterIndex(OrionRequest $request, $entities)
    {
        $this->stampAccessFlags(
            $entities instanceof Paginator ? $entities->items() : $entities,
            (string) $request->user()->id,
        );

        return null;
    }

    protected function afterShow(OrionRequest $request, Model $entity)
    {
        $userId = (string) $request->user()->id;

        $this->stampAccessFlags([$entity], $userId);

        // Lazy per-user state: opening a channel records the membership row and
        // refreshes last_read_at. Never a precondition for access.
        app(ChatService::class)->markChannelRead($entity, $userId);

        return null;
    }

    /**
     * Guard: system channels cannot be deleted.
     */
    protected function performDestroy(Model $entity): void
    {
        /** @var ChatChannel $entity */
        if ($entity->is_system) {
            abort(422, 'Los canales del sistema no pueden eliminarse.');
        }

        parent::performDestroy($entity);
    }

    /**
     * POST /chat-channel/{id}/join
     * Join a channel as a member — the channel access rules
     * (target_type/target_selector) are enforced (§13.2, §13.3.4).
     */
    public function join(string $id, Request $request): JsonResponse
    {
        $channel = ChatChannel::findOrFail($id);
        $userId = (string) $request->user()->id;

        try {
            $member = app(ChatService::class)->joinChannel($channel, $userId);
        } catch (ChatAccessDeniedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $member]);
    }

    /**
     * POST /chat_channel/direct  {user_id}
     * Open (or return) the 1:1 direct channel with another member of the
     * tenant (§13.4.7). Idempotent per user pair.
     */
    public function direct(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => [
                'required',
                'uuid',
                Rule::exists('user_tenant', 'user_id')->where('tenant_id', tenant('id')),
            ],
        ]);

        try {
            $channel = app(ChatService::class)->openDirectChannel(
                (string) $request->user()->id,
                $validated['user_id'],
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $channel->load('members')]);
    }

    /**
     * POST /chat-channel/{id}/copy-config  {source_channel_id}
     * Copy mode + target_type + target_selector from a source channel into this
     * one (§13.4 "copiar config"). Gated by update.chat_channel; both channels
     * must belong to the caller's tenant.
     */
    public function copyConfig(string $id, Request $request): JsonResponse
    {
        $this->authorize('update.chat_channel', ChatChannel::class);

        $validated = $request->validate([
            'source_channel_id' => [
                'required',
                'uuid',
                Rule::exists('chat_channel', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at'),
            ],
        ]);

        if ($validated['source_channel_id'] === $id) {
            return response()->json(['message' => 'El canal origen y destino no pueden ser el mismo.'], 422);
        }

        $target = ChatChannel::findOrFail($id);
        $source = ChatChannel::findOrFail($validated['source_channel_id']);

        $updated = app(ChatService::class)->copyChannelConfig($target, $source);

        return response()->json(['data' => $updated]);
    }

    private function scopeToAccessibleChannels(Builder $query): Builder
    {
        $user = auth()->guard('sanctum')->user();

        if ($user === null || $user->tokenCan('*') || $user->tokenCan('update.chat_channel')) {
            return $query;
        }

        return $query->whereIn(
            'id',
            app(ChatService::class)->accessibleChannelIds((string) $user->id),
        );
    }

    /**
     * @param  iterable<int, mixed>  $channels
     */
    private function stampAccessFlags(iterable $channels, string $userId): void
    {
        $service = app(ChatService::class);

        foreach ($channels as $channel) {
            if ($channel instanceof ChatChannel) {
                $channel->setAttribute('can_read', true);
                $channel->setAttribute('can_write', $service->canWriteToChannel($channel, $userId));
            }
        }
    }
}
