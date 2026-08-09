<?php

namespace Modules\Chat\Services;

use App\Models\User;
use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\NotificationCategory;
use App\Support\MediaAttachmentPolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Modules\Chat\Exceptions\ChatAccessDeniedException;
use Modules\Chat\Exceptions\ChatRateLimitException;
use Modules\Chat\Models\ChatChannel;
use Modules\Chat\Models\ChatChannelMember;
use Modules\Chat\Models\ChatChannelType;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatMessageReport;
use Modules\Chat\Models\ChatReaction;
use Modules\Collections\Services\TargetResolver;
use Modules\Housing\Models\UnitUser;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ChatService
{
    use NotifiesResidents;

    /** Spec §13.4.9 — max messages per minute per user. */
    public const RATE_LIMIT_PER_MINUTE = 30;

    /** Minutes during which the author may still edit their own message. */
    public const EDIT_WINDOW_MINUTES = 15;

    /** Name of the seeded "Avisos oficiales" system channel (no key/slug column — identified by name + is_system + OFFICIAL type). */
    public const OFFICIAL_CHANNEL_NAME = 'Avisos oficiales';

    /** Name of the seeded "Reportes y sugerencias" system channel (open to everyone). */
    public const REPORTS_CHANNEL_NAME = 'Reportes y sugerencias';

    /** Ruta del chat en el frontend (React Router). */
    public const CHAT_ROUTE = '/chat-channel';

    public function __construct(
        private readonly TargetResolver $targetResolver,
        private readonly ChatHtmlSanitizer $htmlSanitizer,
    ) {}

    /**
     * Deep-link a un canal concreto del chat: `/chat-channel?channel=<uuid>`.
     *
     * Se usa como `click_action` de TODA notificación de chat. Es un query param
     * (no un segmento de ruta) porque `/chat-channel/{id}/edit` y
     * `/chat-channel/new` ya existen en el router y un `/chat-channel/{id}` suelto
     * colisionaría con ellas. ChatPage lo lee y preselecciona el canal.
     */
    public static function channelDeepLink(?string $channelId): string
    {
        return $channelId === null
            ? self::CHAT_ROUTE
            : self::CHAT_ROUTE.'?channel='.$channelId;
    }

    /**
     * Platform/cluster admin roles — always eligible for board channels.
     *
     * These are GLOBAL Spatie roles (not tenant-scoped): an admin keeps the role
     * across every tenant. This is the accepted platform limitation. Board
     * eligibility, by contrast, is resolved per-tenant via board_member of the
     * current period (see userIsCurrentBoardMember) so a president/treasurer of
     * tenant A does NOT gain board access in tenant B.
     */
    private const ADMIN_ROLES = ['admin.super', 'account.owner', 'company.admin', 'administrator'];

    /**
     * Create system channels per tenant (idempotent, spec §13.2).
     * Called during tenant seeding. All four are is_system=true (undeletable).
     * Read target = who sees it; write target = who may post:
     *  - "Avisos oficiales" (official, read=all, write=board — only the board posts)
     *  - "General" (general, read=all, write=all)
     *  - "Mesa directiva" (custom, read=board, write=board — board-only)
     *  - "Reportes y sugerencias" (custom, read=all, write=all — residents can post;
     *    also receives the auto-notice ComplaintService posts when a complaint is
     *    registered, see ComplaintService::postReportsChannelNotice)
     */
    public function createSystemChannels(): void
    {
        $officialType = ChatChannelType::find(ChatChannelType::OFFICIAL);
        $generalType = ChatChannelType::find(ChatChannelType::GENERAL);
        $customType = ChatChannelType::find(ChatChannelType::CUSTOM);

        if ($officialType) {
            ChatChannel::firstOrCreate(
                ['name' => self::OFFICIAL_CHANNEL_NAME],
                [
                    'chat_channel_type_id' => $officialType->id,
                    'icon' => '📢',
                    'description' => 'Canal oficial de avisos de la mesa directiva.',
                    'target_type' => 'all',
                    'target_selector' => null,
                    'write_target_type' => 'board',
                    'is_moderated' => false,
                    'is_system' => true,
                    'is_archived' => false,
                ]
            );
        }

        if ($generalType) {
            ChatChannel::firstOrCreate(
                ['name' => 'General'],
                [
                    'chat_channel_type_id' => $generalType->id,
                    'icon' => '💬',
                    'description' => 'Canal general de la comunidad.',
                    'target_type' => 'all',
                    'target_selector' => null,
                    'write_target_type' => 'all',
                    'is_moderated' => false,
                    'is_system' => true,
                    'is_archived' => false,
                ]
            );
        }

        // Mesa directiva — board-only channel. Both targets are board: read=board
        // restricts visibility and write=board keeps posting to the current board.
        $boardChannelType = $customType ?? $officialType;
        if ($boardChannelType) {
            ChatChannel::firstOrCreate(
                ['name' => 'Mesa directiva'],
                [
                    'chat_channel_type_id' => $boardChannelType->id,
                    'icon' => '🏛',
                    'description' => 'Canal privado de la mesa directiva.',
                    'target_type' => 'board',
                    'target_selector' => null,
                    'write_target_type' => 'board',
                    'is_moderated' => false,
                    'is_system' => true,
                    'is_archived' => false,
                ]
            );
        }

        // Reportes y sugerencias — everyone can post (residents file reports/suggestions).
        $reportsChannelType = $customType ?? $generalType;
        if ($reportsChannelType) {
            ChatChannel::firstOrCreate(
                ['name' => self::REPORTS_CHANNEL_NAME],
                [
                    'chat_channel_type_id' => $reportsChannelType->id,
                    'icon' => '🔧',
                    'description' => 'Canal para reportes y sugerencias de la comunidad.',
                    'target_type' => 'all',
                    'target_selector' => null,
                    'write_target_type' => 'all',
                    'is_moderated' => false,
                    'is_system' => true,
                    'is_archived' => false,
                ]
            );
        }
    }

    /**
     * Find the tenant's "Avisos oficiales" system channel. There is no key/slug
     * column on chat_channel, so the seeded channel is identified by its fixed
     * name + is_system + the OFFICIAL type. Tenant-scoped via BelongsToTenant.
     */
    public function findOfficialChannel(): ?ChatChannel
    {
        return ChatChannel::where('name', self::OFFICIAL_CHANNEL_NAME)
            ->where('is_system', true)
            ->where('chat_channel_type_id', ChatChannelType::OFFICIAL)
            ->first();
    }

    /**
     * Find the tenant's "Reportes y sugerencias" system channel. Unlike
     * findOfficialChannel(), this deliberately does NOT filter by
     * chat_channel_type_id: createSystemChannels() seeds it as CUSTOM but
     * falls back to GENERAL when the CUSTOM catalog row is absent, so
     * name + is_system is the only stable identifier. Tenant-scoped via
     * BelongsToTenant.
     */
    public function findReportsChannel(): ?ChatChannel
    {
        return ChatChannel::where('name', self::REPORTS_CHANNEL_NAME)
            ->where('is_system', true)
            ->first();
    }

    /**
     * Post an AUTOMATIC system message to a channel (e.g. an announcement
     * cross-post). Same body/body_html treatment as postMessage (sanitized HTML,
     * derived plain text), but deliberately SKIPS the write guards, rate limit
     * and notifyChannelMessage: the originating flow (e.g. the announcement
     * publish) already notified its audience, so notifying here again would
     * duplicate push/in-app notifications.
     *
     * $mediaToCopy carries pre-existing Media models (e.g. an announcement's
     * attachments) to duplicate onto the new message via Spatie's own
     * `Media::copy()` (design ADR-3): a Media row has exactly ONE morph owner,
     * so copying — not referencing — is the only way to make the resulting
     * ChatMessage self-contained while keeping the owner-based authorization
     * gate (MediaAuthorizationService ADR-2) correct with zero special-casing.
     * No re-validation is done here: the media is already-attached, trusted
     * announcement media, not a fresh upload.
     *
     * @param  array<int, Media>  $mediaToCopy
     */
    public function postSystemMessage(ChatChannel $channel, string $userId, string $body, ?string $bodyHtml = null, array $mediaToCopy = []): ChatMessage
    {
        // Same lazy membership as postMessage: the author gets a per-user state row.
        $this->addMember($channel, $userId);

        $message = $this->createMessageRecord($channel, $userId, $body, $bodyHtml);

        foreach ($mediaToCopy as $media) {
            $media->copy($message, 'attachments');
        }

        return $message;
    }

    /**
     * Shared persistence for postMessage/postSystemMessage: sanitize the HTML,
     * derive the plain-text body and create the visible message. user_id, hidden
     * and body_html are deliberately NOT mass-assignable: author is forced,
     * hidden only via moderation, body_html is the sanitized server output
     * (never raw client HTML).
     */
    private function createMessageRecord(
        ChatChannel $channel,
        string $userId,
        string $body,
        ?string $bodyHtml,
        ?string $replyToId = null,
    ): ChatMessage {
        $cleanHtml = null;
        $plainBody = $body;
        if ($bodyHtml !== null && trim($bodyHtml) !== '') {
            $cleanHtml = $this->htmlSanitizer->sanitize($bodyHtml);
            $plainBody = $this->htmlSanitizer->toPlainText($cleanHtml);
        }

        $message = new ChatMessage([
            'chat_channel_id' => $channel->id,
            'body' => $plainBody,
            'reply_to_id' => $replyToId,
        ]);
        $message->user_id = $userId;
        $message->hidden = false;
        $message->body_html = $cleanHtml;
        $message->save();

        return $message;
    }

    /**
     * Post a message to a channel. The author is ALWAYS the given user id —
     * never client payload — and the message is created visible (hidden=false).
     * Guards: channel access, channel write-mode, same-channel threading and the
     * 30 msg/min rate limit.
     *
     * Rich text (Fase 4): when $bodyHtml is given it is SANITIZED server-side
     * (XSS allowlist) into body_html and the plain-text $body is DERIVED from it
     * (notifications/preview always use the plain text). $attachments are
     * generalized platform-wide file attachments (chat-file-attachments —
     * previously images-only), attached to the 'attachments' collection; each
     * item is ['file' => UploadedFile, 'title' => ?string]. Image mimes still get
     * the WebP conversion (scoped at the model layer, ADR-4); non-image files are
     * stored as-is. This is layer-2 validation (re-checks extension/size/count
     * via MediaAttachmentPolicy independent of the FormRequest — spec's
     * double-layer requirement).
     *
     * @param  array<int, array{file: mixed, title?: ?string}>  $attachments
     *
     * @throws ChatAccessDeniedException when not allowed / channel is read-only
     * @throws ChatRateLimitException when the rate limit is exceeded
     * @throws InvalidArgumentException when the reply belongs to another channel,
     *                                  or an attachment fails whitelist/size/count re-validation
     */
    public function postMessage(
        ChatChannel $channel,
        string $userId,
        string $body,
        ?string $replyToId = null,
        ?string $bodyHtml = null,
        array $attachments = [],
    ): ChatMessage {
        $this->assertChannelAccess($channel, $userId);
        $this->assertCanWriteToChannel($channel, $userId);

        if ($replyToId !== null) {
            $parentChannelId = ChatMessage::where('id', $replyToId)->value('chat_channel_id');
            if ($parentChannelId !== $channel->id) {
                throw new InvalidArgumentException('La respuesta debe pertenecer al mismo canal.');
            }
        }

        $this->assertAttachmentsAllowed($attachments);

        $this->enforceRateLimit($channel->tenant_id ?? tenant()?->getTenantKey() ?? 'unknown', $userId);

        // Lazy membership: the author gets a per-user state row (last_read_at,
        // notif_pref) on first post — membership is NEVER a prerequisite for
        // access, it only carries per-user state (§13.4).
        $this->addMember($channel, $userId);

        $message = $this->createMessageRecord($channel, $userId, $body, $bodyHtml, $replyToId);

        // File attachments → 'attachments' collection. The optional title is
        // stored as the media name; the WebP conversion is scoped to image
        // mimes at the model layer (ChatMessage::registerMediaConversions).
        foreach ($attachments as $attachment) {
            $file = $attachment['file'] ?? null;
            if ($file === null) {
                continue;
            }
            $adder = $message->addMedia($file);
            $title = $attachment['title'] ?? null;
            if (is_string($title) && trim($title) !== '') {
                // The frontend bubble reads media.custom_properties.title — store it
                // there (and also as the media name).
                $adder->usingName(trim($title))->withCustomProperties(['title' => trim($title)]);
            }
            $adder->toMediaCollection('attachments');
        }

        // §13.6: notify the channel AUDIENCE — ALWAYS with the plain-text body,
        // never the HTML. Dispatched after commit; never breaks the send.
        $this->notifyChannelMessage($channel, $message, $userId, $message->body);

        return $message;
    }

    /**
     * Layer-2 re-validation of attachment extension, size and count —
     * independent of the FormRequest (layer 1), so a direct service call that
     * bypasses HTTP validation (e.g. a test, or a future non-HTTP caller) still
     * cannot slip a disallowed file past the whitelist (spec `chat-attachments`
     * "Double-layer validation").
     *
     * @param  array<int, array{file: mixed, title?: ?string}>  $attachments
     *
     * @throws InvalidArgumentException when a file's extension/size is
     *                                  disallowed or the count exceeds the cap
     */
    private function assertAttachmentsAllowed(array $attachments): void
    {
        if (count($attachments) > MediaAttachmentPolicy::MAX_FILES) {
            throw new InvalidArgumentException('Máximo '.MediaAttachmentPolicy::MAX_FILES.' adjuntos por mensaje.');
        }

        foreach ($attachments as $attachment) {
            $file = $attachment['file'] ?? null;
            if (! $file instanceof UploadedFile) {
                continue;
            }

            if (! MediaAttachmentPolicy::isAllowedExtension((string) $file->getClientOriginalExtension())) {
                throw new InvalidArgumentException('Tipo de archivo no permitido.');
            }

            if (! MediaAttachmentPolicy::withinSizeLimit($file->getSize() ?: 0)) {
                throw new InvalidArgumentException('El archivo supera el límite de 10 MB.');
            }
        }
    }

    /**
     * §13.6 / Fase 3 message notifications. Recipients are the channel's AUDIENCE
     * resolved by target_type (channelAudienceUserIds), NOT just members:
     *  - the audience with notif_pref != 'none'/'mentions' (excluding the author),
     *  - @mentioned audience members whose notif_pref allows mentions ('all'/'mentions'),
     *  - the author of the replied-to message ("Respuesta a mensaje propio"),
     *    unless their notif_pref is 'none' (deduped against the broadcast).
     *
     * notif_pref comes from the chat_channel_member row when it exists; audience
     * members without a row default to 'all'. The channel's notify_on_post flag
     * gates everything. Mentions are parsed as @user_id tokens in the body.
     */
    private function notifyChannelMessage(ChatChannel $channel, ChatMessage $message, string $authorId, string $body): void
    {
        // Fase 2 flag: a channel can opt out of post notifications entirely. Only
        // an EXPLICIT false suppresses — null (column default / not-yet-loaded) keeps
        // the default-on behavior so system channels created without the flag notify.
        if ($channel->notify_on_post === false) {
            return;
        }

        $audience = array_values(array_diff($this->channelAudienceUserIds($channel), [$authorId]));

        if ($audience === []) {
            return;
        }

        // Per-user notif_pref from the membership row when present; default 'all'.
        $prefs = ChatChannelMember::where('chat_channel_id', $channel->id)
            ->whereIn('user_id', $audience)
            ->pluck('notif_pref', 'user_id')
            ->all();
        $prefFor = static fn (string $userId): string => $prefs[$userId] ?? 'all';

        $isDirect = $channel->target_type === 'direct';
        $title = $isDirect
            ? 'Nuevo mensaje directo'
            : 'Nuevo mensaje en '.($channel->name ?? 'el canal');
        $preview = mb_substr($body, 0, 200);
        $data = [
            // El canal viaja EN la URL (no sólo en el payload): los handlers de la
            // campana y del push sólo hacen navigate(click_action), así que sin el
            // query param el destinatario aterriza en el primer canal de la lista
            // ("Avisos oficiales") en vez del canal del mensaje.
            'click_action' => self::channelDeepLink($channel->id),
            'chat_channel_id' => $channel->id,
            'chat_message_id' => $message->id,
            'category' => NotificationCategory::CHAT,
        ];

        // §13.6 'Respuesta a mensaje propio → autor original' — resolved first so the
        // broadcast can be deduped against it.
        $replyAuthorId = null;
        if ($message->reply_to_id !== null) {
            $parentAuthorId = ChatMessage::where('id', $message->reply_to_id)->value('user_id');
            if ($parentAuthorId !== null
                && $parentAuthorId !== $authorId
                && in_array($parentAuthorId, $audience, true)
                && $prefFor($parentAuthorId) !== 'none') {
                $replyAuthorId = $parentAuthorId;
            }
        }

        // Broadcast to audience members who want all messages (reply author separate).
        $broadcastIds = array_values(array_filter(
            $audience,
            static fn (string $userId): bool => ! in_array($prefFor($userId), ['none', 'mentions'], true)
                && $userId !== $replyAuthorId,
        ));

        if ($broadcastIds !== []) {
            $this->notifyUsers($broadcastIds, $title, $preview, $data);
        }

        if ($replyAuthorId !== null) {
            $this->notifyUsers(
                [$replyAuthorId],
                'Respondieron a tu mensaje en '.($channel->name ?? 'el canal'),
                $preview,
                $data,
            );
        }

        // Mentions: @user_id tokens that match audience members who allow mention notifs.
        $mentionedIds = $this->parseMentions($body);
        if ($mentionedIds !== []) {
            $mentionRecipients = array_values(array_filter(
                $audience,
                static fn (string $userId): bool => in_array($userId, $mentionedIds, true)
                    && in_array($prefFor($userId), ['all', 'mentions'], true),
            ));

            if ($mentionRecipients !== []) {
                $this->notifyUsers(
                    $mentionRecipients,
                    'Te mencionaron en '.($channel->name ?? 'el canal'),
                    $preview,
                    $data,
                );
            }
        }
    }

    /**
     * Resolve the LIST of user ids that make up a channel's notification audience,
     * using the SAME unit-based target system as announcements (the fan-out
     * counterpart of userMatchesChannelTarget):
     *  - 'board'   → current board period members (tenant-scoped)
     *  - 'direct'  → the explicit members (the 1:1 pair)
     *  - any TargetResolver target (all/by_type/by_tower/by_floor/by_feature/
     *    single_unit/manual/by_share) → active residents (unit_user end_date null)
     *    of the units the TargetResolver resolves — exactly like
     *    AnnouncementService::audienceUserIds.
     *
     * @return array<int, string>
     */
    public function channelAudienceUserIds(ChatChannel $channel): array
    {
        $targetType = $channel->target_type ?? 'all';

        if ($targetType === 'board') {
            return $this->currentBoardMemberUserIds();
        }

        if ($targetType === 'direct') {
            return ChatChannelMember::where('chat_channel_id', $channel->id)->pluck('user_id')->all();
        }

        if (! in_array($targetType, TargetResolver::TARGET_TYPES, true)) {
            return [];
        }

        $unitIds = $this->targetResolver->resolve($targetType, $channel->target_selector)
            ->pluck('id')
            ->all();

        if ($unitIds === []) {
            return [];
        }

        return UnitUser::whereIn('unit_id', $unitIds)
            ->whereNull('end_date')
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether the user has an active unit_user (end_date null) in any of the units
     * the TargetResolver resolves for the channel's target_type/selector. Per-user
     * check (exists), not a full audience resolution.
     *
     * @param  array<string, mixed>  $selector
     */
    private function userHasActiveUnitInTarget(string $userId, string $targetType, array $selector): bool
    {
        $unitIds = $this->targetResolver->resolve($targetType, $selector)->pluck('id')->all();

        if ($unitIds === []) {
            return false;
        }

        return UnitUser::where('user_id', $userId)
            ->whereNull('end_date')
            ->whereIn('unit_id', $unitIds)
            ->exists();
    }

    /**
     * Parse @mention tokens (@user_id) from a message body.
     *
     * @return array<int, string>
     */
    private function parseMentions(string $body): array
    {
        if (! preg_match_all('/@([0-9a-fA-F-]{8,})/', $body, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * Whether a user matches a channel's audience, computed PER-USER from the
     * channel's target_type/target_selector using the SAME unit-based vocabulary
     * as charges/announcements (Collections TargetResolver) — never manual
     * chat_channel_member rows (§13.2/§13.3.4):
     *
     *  - null|'all'|'by_share'                              → any tenant member
     *  - 'board'                                            → current board member (or admin role)
     *  - 'direct'                                           → the 1:1 pair (explicit members)
     *  - by_type|by_tower|by_floor|by_feature|single_unit|manual|by_section|with_vehicles
     *        → the user has an active unit_user (end_date null) in a unit the
     *          TargetResolver resolves for that target_type/selector
     */
    public function userMatchesChannelTarget(ChatChannel $channel, string $userId): bool
    {
        // READ access uses the channel's read target (target_type/target_selector).
        return $this->matchesTarget($channel, $userId, $channel->target_type, $channel->target_selector);
    }

    /**
     * Whether a user matches a (target_type, selector) pair on a channel — the
     * shared resolver used by BOTH the read target (who sees/receives) and the
     * write target (who may post). Same unit-based vocabulary as charges:
     *
     *  - null|'all'|'by_share' → any tenant member
     *  - 'board'               → current board member (or admin role)
     *  - 'direct'              → the 1:1 pair (explicit members)
     *  - by_type|by_tower|by_floor|by_feature|single_unit|manual|by_section|with_vehicles
     *        → active unit_user in a unit the TargetResolver resolves
     *
     * @param  array<string, mixed>|null  $selector
     */
    private function matchesTarget(ChatChannel $channel, string $userId, ?string $targetType, ?array $selector): bool
    {
        return match ($targetType) {
            // 'all'/'by_share' = any tenant member (board, staff and admins reach
            // the General channel, not only unit-holding residents). The
            // notification AUDIENCE for these still targets residents-of-units.
            null, 'all', 'by_share' => true,
            'board' => $this->userIsCurrentBoardMember($userId)
                || $this->userHasAnyRole($userId, self::ADMIN_ROLES),
            'direct' => $this->isExplicitMember($channel, $userId),
            'by_type', 'by_tower', 'by_floor', 'by_feature', 'single_unit', 'manual', 'by_section', 'with_vehicles' => $this->userHasActiveUnitInTarget($userId, $targetType, $selector ?? []),
            default => false,
        };
    }

    /**
     * Whether a user can ACCESS a channel: they match its target, OR they are a
     * cluster moderator (admin role / update.chat_message) who oversees every
     * channel. Access drives reads AND is the precondition for writing.
     */
    public function canAccessChannel(ChatChannel $channel, string $userId): bool
    {
        return $this->userMatchesChannelTarget($channel, $userId)
            || $this->userCanModerate($userId);
    }

    /**
     * Whether a user can WRITE to a channel: they must have read access AND match
     * the channel's WRITE target (write_target_type/write_target_selector) — or be
     * a moderator. Returned to the frontend as the per-channel `can_write` flag.
     */
    public function canWriteToChannel(ChatChannel $channel, string $userId): bool
    {
        return $this->canAccessChannel($channel, $userId)
            && $this->writeModeAllows($channel, $userId);
    }

    /**
     * The ids of every channel the user can access in the current tenant. Drives
     * the message/channel read scopes. Channel counts per tenant are small, so a
     * per-user pass over the tenant's channels is cheap and avoids leaking the
     * target logic into SQL.
     *
     * @return array<int, string>
     */
    public function accessibleChannelIds(string $userId): array
    {
        return ChatChannel::query()
            ->get()
            ->filter(fn (ChatChannel $channel): bool => $this->userMatchesChannelTarget($channel, $userId))
            ->pluck('id')
            ->all();
    }

    /**
     * Guard: the user must be able to access the channel before interacting with
     * it (post/react/report). Membership is NOT required — access is by target.
     *
     * @throws ChatAccessDeniedException
     */
    public function assertChannelAccess(ChatChannel $channel, string $userId): void
    {
        if (! $this->canAccessChannel($channel, $userId)) {
            throw new ChatAccessDeniedException('No tienes acceso a este canal.');
        }
    }

    /**
     * Enforce the channel WRITE target. Only users matching write_target_* (e.g. the
     * board on the official channel) — or moderators — may post; everyone else with
     * read access is effectively read-only there.
     *
     * @throws ChatAccessDeniedException
     */
    private function assertCanWriteToChannel(ChatChannel $channel, string $userId): void
    {
        if (! $this->writeModeAllows($channel, $userId)) {
            throw new ChatAccessDeniedException('This channel is read-only for residents.');
        }
    }

    /**
     * Spec §13.2/§13.3.4 — channel access rules for the explicit join endpoint.
     * Delegates to the per-user target matcher; 'direct' channels keep their
     * pair-based rule (only the two participants from target_selector).
     *
     * @throws ChatAccessDeniedException
     */
    public function assertCanJoinChannel(ChatChannel $channel, string $userId): void
    {
        $allowed = $channel->target_type === 'direct'
            ? in_array($userId, $this->selectorValues($channel->target_selector ?? [], 'user_ids'), true)
            : $this->userMatchesChannelTarget($channel, $userId);

        if (! $allowed) {
            throw new ChatAccessDeniedException('No cumples las reglas de acceso de este canal.');
        }
    }

    /**
     * Ensure the per-user membership state row exists and refresh last_read_at —
     * called when a user OPENS a channel (read). Membership only carries per-user
     * state (last_read_at, notif_pref); it never gates access.
     */
    public function markChannelRead(ChatChannel $channel, string $userId): ChatChannelMember
    {
        $member = $this->addMember($channel, $userId);
        $member->forceFill(['last_read_at' => now()])->save();

        return $member;
    }

    /**
     * Copy the access configuration from a source channel into a target channel —
     * the "copiar config" shortcut. Copies BOTH targets: read (who sees/receives)
     * and write (who may post). Name, icon, type and is_system are NOT copied.
     */
    public function copyChannelConfig(ChatChannel $target, ChatChannel $source): ChatChannel
    {
        $target->fill([
            'target_type' => $source->target_type,
            'target_selector' => $source->target_selector,
            'write_target_type' => $source->write_target_type,
            'write_target_selector' => $source->write_target_selector,
        ]);
        $target->save();

        return $target;
    }

    /**
     * Whether the user may WRITE: they match the channel's WRITE target
     * (write_target_type/write_target_selector — same vocabulary as the read
     * target), OR they are a platform moderator (admin role / update.chat_message)
     * who may post anywhere. The deprecated `mode` column is no longer consulted;
     * a channel without an explicit write target falls back to its read target.
     */
    private function writeModeAllows(ChatChannel $channel, string $userId): bool
    {
        if ($this->userCanModerate($userId)) {
            return true;
        }

        $writeType = $channel->write_target_type ?? $channel->target_type;
        $writeSelector = $channel->write_target_type !== null
            ? $channel->write_target_selector
            : $channel->target_selector;

        return $this->matchesTarget($channel, $userId, $writeType, $writeSelector);
    }

    /**
     * Whether a user is a cluster moderator with oversight of every channel
     * (admin role or the update.chat_message permission). Used as the access
     * bypass so admins reach channels they do not match by target.
     */
    private function userCanModerate(string $userId): bool
    {
        $user = User::withoutGlobalScopes()->find($userId);

        return $user !== null && (
            $user->hasRole(self::ADMIN_ROLES)
            || $user->can('update.chat_message')
        );
    }

    /**
     * Whether the user is a board member of THIS tenant's current board period.
     *
     * This is the tenant-scoped board check (board_member + board_period are
     * BelongsToTenant), used instead of the GLOBAL Spatie board roles so a
     * president/treasurer of another tenant does not leak board access here.
     * Reuses NotifiesResidents::currentBoardMemberUserIds() (same resolution as
     * AnnouncementService).
     */
    private function userIsCurrentBoardMember(string $userId): bool
    {
        return in_array($userId, $this->currentBoardMemberUserIds(), true);
    }

    /**
     * Whether the user has an explicit membership row in the channel (used only
     * for 'direct' 1:1 channels, whose pair are members from open time).
     */
    private function isExplicitMember(ChatChannel $channel, string $userId): bool
    {
        return ChatChannelMember::where('chat_channel_id', $channel->id)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * Hide a message via moderation (soft-hide — never deleted). Resolves any
     * pending reports on the message as reviewed (§13.5 paso 8).
     */
    public function hideMessage(ChatMessage $message): ChatMessage
    {
        $message->hidden = true;
        $message->save();

        ChatMessageReport::where('chat_message_id', $message->id)
            ->where('status', ChatMessageReport::STATUS_PENDING)
            ->get()
            ->each(function (ChatMessageReport $reportRecord): void {
                $reportRecord->status = ChatMessageReport::STATUS_REVIEWED;
                $reportRecord->save();
            });

        // §13.6: notify the author that their own message was hidden by moderation.
        if ($message->user_id) {
            $this->notifyUsers(
                [$message->user_id],
                'Tu mensaje fue ocultado',
                'Un moderador ocultó uno de tus mensajes en el chat de la comunidad.',
                [
                    'click_action' => self::channelDeepLink($message->chat_channel_id),
                    'chat_message_id' => $message->id,
                    'chat_channel_id' => $message->chat_channel_id,
                    'category' => NotificationCategory::CHAT,
                ],
            );
        }

        return $message;
    }

    /**
     * Reverse a moderation hide. Moderation never rewrites content — hide/unhide
     * are the only moderation verbs on a message body.
     */
    public function unhideMessage(ChatMessage $message): ChatMessage
    {
        $message->hidden = false;
        $message->save();

        return $message;
    }

    /**
     * Author-only edit of a message body within the edit window. Stamps
     * edited_at server-side (§13.4.6 'editado' indicator). Moderators do NOT
     * edit through here — they hide/unhide.
     *
     * @throws ChatAccessDeniedException when the caller is not the author
     * @throws InvalidArgumentException when hidden or the window expired
     */
    public function editOwnMessage(ChatMessage $message, string $userId, string $body): ChatMessage
    {
        if ($message->user_id !== $userId) {
            throw new ChatAccessDeniedException('Solo el autor puede editar su mensaje.');
        }

        if ($message->hidden) {
            throw new InvalidArgumentException('Un mensaje ocultado por moderación no puede editarse.');
        }

        if ($message->created_at !== null && $message->created_at->lt(now()->subMinutes(self::EDIT_WINDOW_MINUTES))) {
            throw new InvalidArgumentException('La ventana de edición ha expirado.');
        }

        $message->body = $body;
        $message->body_html = null;
        $message->edited_at = now();
        $message->save();

        return $message;
    }

    /**
     * Add a reaction to a message (idempotent per user+emoji).
     */
    public function react(ChatMessage $message, string $userId, string $emoji): ChatReaction
    {
        $this->assertChannelAccess($message->channel, $userId);

        return ChatReaction::firstOrCreate(
            [
                'chat_message_id' => $message->id,
                'user_id' => $userId,
                'emoji' => $emoji,
            ]
        );
    }

    /**
     * Remove a reaction from a message. Requires channel membership (mirrors react()).
     */
    public function unreact(ChatMessage $message, string $userId, string $emoji): void
    {
        $this->assertChannelAccess($message->channel, $userId);

        ChatReaction::where('chat_message_id', $message->id)
            ->where('user_id', $userId)
            ->where('emoji', $emoji)
            ->delete();
    }

    /**
     * Report a message for moderation. Creates a report with status=pending.
     * One report per user per message — duplicates are rejected so the board
     * cannot be push-spammed (§13.5).
     *
     * @throws ChatAccessDeniedException when the reporter is not a channel member
     * @throws InvalidArgumentException when the user already reported the message
     */
    public function report(ChatMessage $message, string $reporterUserId, string $reason): ChatMessageReport
    {
        $this->assertChannelAccess($message->channel, $reporterUserId);

        $alreadyReported = ChatMessageReport::where('chat_message_id', $message->id)
            ->where('reporter_user_id', $reporterUserId)
            ->exists();

        if ($alreadyReported) {
            throw new InvalidArgumentException('Ya reportaste este mensaje.');
        }

        $report = ChatMessageReport::create([
            'chat_message_id' => $message->id,
            'reporter_user_id' => $reporterUserId,
            'reason' => $reason,
            'status' => ChatMessageReport::STATUS_PENDING,
        ]);

        // §13.6: notify President + Secretary that a message was reported for moderation.
        $this->notifyUsers(
            $this->userIdsWithRole(['president', 'secretary']),
            'Mensaje reportado',
            'Un residente reportó un mensaje en el chat de la comunidad. Revisa el reporte.',
            [
                'click_action' => self::channelDeepLink($message->chat_channel_id),
                'chat_message_report_id' => $report->id,
                'chat_message_id' => $message->id,
                'chat_channel_id' => $message->chat_channel_id,
                'category' => NotificationCategory::CHAT,
            ],
        );

        return $report;
    }

    /**
     * Dismiss a pending report — the message stays visible (§13.5 paso 8).
     *
     * @throws InvalidArgumentException when the report was already resolved
     */
    public function dismissReport(ChatMessageReport $report): ChatMessageReport
    {
        if ($report->status !== ChatMessageReport::STATUS_PENDING) {
            throw new InvalidArgumentException('El reporte ya fue revisado.');
        }

        $report->status = ChatMessageReport::STATUS_DISMISSED;
        $report->save();

        return $report;
    }

    /**
     * Join a channel — validates the channel access rules before creating the
     * membership (idempotent).
     *
     * @throws ChatAccessDeniedException when the user is not eligible
     */
    public function joinChannel(ChatChannel $channel, string $userId, string $notifPref = 'all'): ChatChannelMember
    {
        $this->assertCanJoinChannel($channel, $userId);

        return $this->addMember($channel, $userId, $notifPref);
    }

    /**
     * Create a membership WITHOUT eligibility checks. Internal use (system
     * flows, direct channels) and channel managers curating members.
     */
    public function addMember(ChatChannel $channel, string $userId, string $notifPref = 'all'): ChatChannelMember
    {
        return ChatChannelMember::firstOrCreate(
            [
                'chat_channel_id' => $channel->id,
                'user_id' => $userId,
            ],
            [
                'notif_pref' => $notifPref,
            ]
        );
    }

    /**
     * Find or create the 1:1 direct channel between two users (§13.4.7).
     * Idempotent: the channel is keyed by the sorted user-id pair (backed by the
     * unique [tenant_id, name] index) and both memberships are ensured.
     *
     * @throws InvalidArgumentException when opening a chat with oneself or the
     *                                  direct channel type is not seeded
     */
    public function openDirectChannel(string $userIdA, string $userIdB): ChatChannel
    {
        if ($userIdA === $userIdB) {
            throw new InvalidArgumentException('No puedes abrir un chat directo contigo mismo.');
        }

        $directType = ChatChannelType::find(ChatChannelType::DIRECT);
        if (! $directType) {
            throw new InvalidArgumentException('El catálogo de tipos de canal no está sembrado.');
        }

        $pair = [$userIdA, $userIdB];
        sort($pair);

        $channel = ChatChannel::firstOrCreate(
            ['name' => 'direct:'.$pair[0].':'.$pair[1]],
            [
                'chat_channel_type_id' => $directType->id,
                'icon' => '👤',
                'description' => null,
                'target_type' => 'direct',
                'target_selector' => ['user_ids' => $pair],
                'is_moderated' => false,
                'is_system' => false,
                'is_archived' => false,
            ]
        );

        $this->addMember($channel, $pair[0]);
        $this->addMember($channel, $pair[1]);

        return $channel;
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function userHasAnyRole(string $userId, array $roles): bool
    {
        if ($roles === []) {
            return false;
        }

        $user = User::withoutGlobalScopes()->find($userId);

        return $user !== null && $user->hasRole($roles);
    }

    /**
     * Read a target_selector id list: accepts both the keyed shape
     * {"tower_ids": [...]} and a flat list [...] for robustness.
     *
     * @return array<int, string>
     */
    private function selectorValues(mixed $selector, string $key): array
    {
        if (! is_array($selector)) {
            return [];
        }

        if (array_is_list($selector)) {
            return $selector;
        }

        return array_values((array) ($selector[$key] ?? []));
    }

    /**
     * Rate-limit guard: max 30 messages per minute per user, enforced with an
     * atomic Cache::add + Cache::increment counter (fixed 60s window).
     *
     * @throws ChatRateLimitException
     */
    private function enforceRateLimit(string $tenantId, string $userId): void
    {
        $key = "chat_rate:{$tenantId}:{$userId}";

        if (Cache::add($key, 1, 60)) {
            return;
        }

        $count = (int) Cache::increment($key);

        if ($count > self::RATE_LIMIT_PER_MINUTE) {
            throw new ChatRateLimitException('Rate limit exceeded: maximum 30 messages per minute.');
        }
    }
}
