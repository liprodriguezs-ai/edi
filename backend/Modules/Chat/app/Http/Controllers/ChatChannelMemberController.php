<?php

namespace Modules\Chat\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Exceptions\ChatAccessDeniedException;
use Modules\Chat\Models\ChatChannel;
use Modules\Chat\Models\ChatChannelMember;
use Modules\Chat\Services\ChatService;
use Orion\Http\Requests\Request as OrionRequest;
use QCH\Core\Http\Controllers\Controller;

class ChatChannelMemberController extends Controller
{
    protected $model = ChatChannelMember::class;

    protected $keyForPermission = 'chat_channel_member';

    public function includes(): array
    {
        return ['channel', 'user'];
    }

    public function filterableBy(): array
    {
        return ['chat_channel_id', 'user_id', 'notif_pref'];
    }

    public function sortableBy(): array
    {
        return ['created_at', 'last_read_at'];
    }

    /**
     * §13.2: member lists are per-channel data — only visible to members of the
     * channel (channel managers excepted).
     */
    protected function buildIndexFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        return $this->scopeToCallerChannels(parent::buildIndexFetchQuery($request, $requestedRelations));
    }

    protected function buildShowFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        return $this->scopeToCallerChannels(parent::buildShowFetchQuery($request, $requestedRelations));
    }

    /**
     * Adding a member enforces the channel access rules for the TARGET user
     * unless the caller manages channels (update.chat_channel). Idempotent on
     * duplicates (unique tenant+channel+user).
     */
    protected function performStore(OrionRequest $request, Model $entity, array $attributes): void
    {
        $validated = $request->validated();

        $channel = ChatChannel::findOrFail($validated['chat_channel_id']);
        $targetUserId = (string) $validated['user_id'];
        $service = app(ChatService::class);

        try {
            $member = $this->callerManagesChannels()
                ? $service->addMember($channel, $targetUserId, $validated['notif_pref'] ?? 'all')
                : $service->joinChannel($channel, $targetUserId, $validated['notif_pref'] ?? 'all');
        } catch (ChatAccessDeniedException $e) {
            abort(403, $e->getMessage());
        }

        $entity->setRawAttributes($member->getAttributes());
        $entity->syncOriginal();
        $entity->exists = true;
    }

    private function scopeToCallerChannels(Builder $query): Builder
    {
        if ($this->callerManagesChannels()) {
            return $query;
        }

        $userId = (string) auth()->guard('sanctum')->id();

        return $query->whereIn(
            'chat_channel_id',
            ChatChannelMember::query()->where('user_id', $userId)->select('chat_channel_id'),
        );
    }

    private function callerManagesChannels(): bool
    {
        $user = auth()->guard('sanctum')->user();

        return $user !== null && ($user->tokenCan('update.chat_channel') || $user->tokenCan('*'));
    }
}
