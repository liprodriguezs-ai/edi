<?php

namespace Modules\Announcements\Services;

use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\NotificationCategory;
use App\Services\RichTextSanitizer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Announcements\Models\Announcement;
use Modules\Announcements\Models\AnnouncementPriority;
use Modules\Announcements\Models\AnnouncementRead;
use Modules\Announcements\Models\AnnouncementStatus;
use Modules\Chat\Services\ChatService;
use Modules\Collections\Services\TargetResolver;
use Modules\Housing\Models\UnitUser;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class AnnouncementService
{
    use NotifiesResidents;

    /**
     * Allowed audience scopes (§12.3.3). 'board' = "Solo mesa directiva" and is
     * resolved against the current board period's members, not against units.
     * Unknown values must be rejected at validation time — TargetResolver's
     * default would silently fan out to the WHOLE cluster otherwise.
     *
     * @var string[]
     */
    public const TARGET_TYPES = [
        'all',
        'by_type',
        'by_tower',
        'by_floor',
        'manual',
        'by_feature',
        'single_unit',
        'by_section',
        'with_vehicles',
        'board',
    ];

    public const MAX_ATTACHMENTS = 5;

    public function __construct(
        private readonly TargetResolver $targetResolver,
        private readonly AnnouncementAudienceLabeler $audienceLabeler,
    ) {}

    /**
     * Publish a draft announcement.
     *
     * - Forces channel_push = true for urgent priority (§12.4 r3). SMS is disabled
     *   for now (channel_sms is left untouched); re-enable it in channelsFor().
     * - With a FUTURE publish_at the announcement stays DRAFT (scheduled): nothing is
     *   notified now; announcements:process-scheduled activates it (and notifies) when
     *   publish_at is reached (§12.3.3 / §12.6 "Aviso programado activado").
     * - Otherwise sets status to published, defaults publish_at to now and notifies
     *   the resolved audience through the channels mandated by the priority (§12.6).
     */
    public function publish(Announcement $announcement): Announcement
    {
        $publishedStatus = AnnouncementStatus::findOrFail(AnnouncementStatus::PUBLISHED);

        // Captured BEFORE the status flips: the chat cross-post only happens on the
        // draft → published transition, so re-publishing never duplicates the message.
        $wasDraft = $announcement->announcement_status_id === AnnouncementStatus::DRAFT;

        if ($announcement->announcement_priority_id === AnnouncementPriority::URGENT) {
            $announcement->channel_push = true;
        }

        if ($announcement->publish_at !== null && $announcement->publish_at->isFuture()) {
            $announcement->save();

            return $announcement->fresh(['type', 'priority', 'status']);
        }

        $announcement->announcement_status_id = $publishedStatus->id;

        if ($announcement->publish_at === null) {
            $announcement->publish_at = now();
        }

        $announcement->save();

        $this->dispatchPublishedNotifications($announcement);

        if ($wasDraft) {
            $this->postToOfficialChatChannel($announcement);
        }

        return $announcement->fresh(['type', 'priority', 'status']);
    }

    /**
     * Archive an announcement (never deletes, keeps row for audit).
     */
    public function archive(Announcement $announcement): Announcement
    {
        $archivedStatus = AnnouncementStatus::findOrFail(AnnouncementStatus::ARCHIVED);
        $announcement->announcement_status_id = $archivedStatus->id;
        $announcement->save();

        return $announcement->fresh(['type', 'priority', 'status']);
    }

    /**
     * Update an announcement's content.
     *
     * §12.4 r2: editing a PUBLISHED announcement stamps edited_at server-side.
     * §12.6: "Aviso editado" notifies the users who had ALREADY read it.
     */
    public function update(Announcement $announcement, array $attributes): Announcement
    {
        $wasPublished = $announcement->announcement_status_id === AnnouncementStatus::PUBLISHED;

        $announcement->fill($attributes);

        // Rich text (dual field): sanitize the client HTML into content_html (never
        // mass-assignable) and derive the plain-text content. Runs BEFORE the dirty
        // check so a body edit is detected and notifies prior readers.
        $this->applyRichTextContent($announcement, $attributes);

        $visibleContentChanged = $announcement->isDirty(['title', 'content']);

        if ($wasPublished && $announcement->isDirty()) {
            $announcement->edited_at = now();
        }

        $announcement->save();

        if ($wasPublished && $visibleContentChanged) {
            $this->notifyReadersOfEdit($announcement);
        }

        return $announcement->fresh(['type', 'priority', 'status']);
    }

    /**
     * Apply the announcement body from the request, supporting BOTH inputs (mirrors
     * Modules/Chat's body/body_html split):
     *
     *  - content_html present & non-empty → sanitize it into content_html (set
     *    DIRECTLY, never mass-assignable) and DERIVE the plain-text content from it;
     *    the client's plain `content` is NOT trusted in this case.
     *  - only content present (plain text, no HTML) → keep the plain content as
     *    given and clear content_html (the reader falls back to plain content).
     *  - neither present (e.g. a partial update touching only the title) → leave
     *    content/content_html untouched.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function applyRichTextContent(Announcement $announcement, array $attributes): void
    {
        $html = $attributes['content_html'] ?? null;

        if (is_string($html) && trim($html) !== '') {
            $clean = RichTextSanitizer::sanitize($html);

            $announcement->content_html = $clean;
            $announcement->content = RichTextSanitizer::toPlainText($clean);

            return;
        }

        // No usable HTML: fall back to the plain-text path. A plain `content` is kept
        // as-is and content_html is cleared (the reader shows plain content). When
        // neither field is present nothing changes (partial update of other fields).
        if (array_key_exists('content', $attributes) && $attributes['content'] !== null) {
            $announcement->content = (string) $attributes['content'];
            $announcement->content_html = null;
        }
    }

    /**
     * Mark an announcement as read by a user (one row per user; read_at is
     * refreshed on every subsequent open — §12.5 step 7).
     */
    public function markRead(Announcement $announcement, string $userId): AnnouncementRead
    {
        $read = AnnouncementRead::firstOrCreate(
            [
                'announcement_id' => $announcement->id,
                'user_id' => $userId,
            ],
            [
                'read_at' => now(),
            ]
        );

        if (! $read->wasRecentlyCreated) {
            $read->update(['read_at' => now()]);
        }

        return $read;
    }

    /**
     * Resolve the audience of an announcement to user ids.
     *
     * 'board' resolves to the current board period's members; 'all' resolves to
     * EVERY active resident of the tenant (unit_user with end_date null) WITHOUT the
     * Collections billability filter — so the notified set matches the visible set
     * (filterVisibleTo treats 'all' as everyone; a resident of an inactive/non-billable
     * unit must still be notified, not just shown the announcement). Every other target
     * resolves units via TargetResolver and maps them to ACTIVE residents through the
     * unit_user pivot.
     *
     * @return array<int, string>
     */
    public function audienceUserIds(Announcement $announcement): array
    {
        return $this->audienceUserIdsFor($announcement->target_type, $announcement->target_selector);
    }

    /**
     * Resolve an arbitrary (target_type, target_selector) pair to resident user ids
     * WITHOUT a persisted Announcement — the preview endpoint counts these so the form
     * can show "llegará a N residentes" before saving.
     *
     * Same three branches as audienceUserIds(): 'board' → current board members;
     * 'all' → every active resident (skips the Collections billability filter so the
     * notified set matches the visible set); every other target → TargetResolver units
     * mapped to ACTIVE residents through the unit_user pivot.
     *
     * @return array<int, string>
     */
    public function audienceUserIdsFor(string $targetType, ?array $selector): array
    {
        if ($targetType === 'board') {
            return $this->currentBoardMemberUserIds();
        }

        // 'all' is an announcement audience, not a billing target: resolve active
        // residents directly (UnitUser is tenant-scoped via BelongsToTenant) so units
        // excluded by Collections' billability filter still receive the notification.
        if ($targetType === 'all') {
            return UnitUser::whereNull('end_date')
                ->pluck('user_id')
                ->unique()
                ->values()
                ->all();
        }

        // Id-only resolve: preview/audience never needs full Unit models, only ids to
        // map through the unit_user pivot.
        $unitIds = $this->targetResolver->resolveUnitIds($targetType, $selector);

        if ($unitIds->isEmpty()) {
            return [];
        }

        return UnitUser::whereIn('unit_id', $unitIds->all())
            ->whereNull('end_date')
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Filter a set of published announcements down to the ones VISIBLE to the
     * given user (§12.3.3 Alcance): 'all' → everyone in the tenant; 'board' →
     * current board members; unit-scoped targets → users of the targeted units.
     * The author always sees their own announcement.
     *
     * @param  Collection<int, Announcement>  $announcements
     * @return Collection<int, Announcement>
     */
    public function filterVisibleTo(Collection $announcements, string $userId): Collection
    {
        $userUnitIds = null;
        $boardMemberIds = null;
        $resolvedTargetCache = [];

        return $announcements->filter(function (Announcement $announcement) use ($userId, &$userUnitIds, &$boardMemberIds, &$resolvedTargetCache): bool {
            if ($announcement->target_type === 'all') {
                return true;
            }

            if ($announcement->author_user_id === $userId) {
                return true;
            }

            if ($announcement->target_type === 'board') {
                $boardMemberIds ??= $this->currentBoardMemberUserIds();

                return in_array($userId, $boardMemberIds, true);
            }

            $userUnitIds ??= UnitUser::where('user_id', $userId)
                ->whereNull('end_date')
                ->pluck('unit_id')
                ->all();

            if ($userUnitIds === []) {
                return false;
            }

            $cacheKey = md5($announcement->target_type.'|'.json_encode($announcement->target_selector));

            if (! array_key_exists($cacheKey, $resolvedTargetCache)) {
                $resolvedTargetCache[$cacheKey] = $this->targetResolver
                    ->resolve($announcement->target_type, $announcement->target_selector)
                    ->pluck('id')
                    ->all();
            }

            return array_intersect($userUnitIds, $resolvedTargetCache[$cacheKey]) !== [];
        });
    }

    /**
     * Attach a file to the announcement's `attachments` collection.
     * Max 5 files per announcement and max 10 MB per file — a 6th file is
     * REJECTED (§12.4 r6), never silently rotated out.
     *
     * @throws \InvalidArgumentException when limits are exceeded.
     */
    public function attachFile(Announcement $announcement, UploadedFile $file): Media
    {
        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4', 'mp3', 'doc', 'docx', 'xls', 'xlsx'];

        if (! in_array(strtolower($file->getClientOriginalExtension()), $allowed, true)) {
            throw new \InvalidArgumentException('Tipo de archivo no permitido.');
        }

        $maxBytes = 10 * 1024 * 1024; // 10 MB

        if ($file->getSize() > $maxBytes) {
            throw new \InvalidArgumentException('El archivo supera el límite de 10 MB.');
        }

        $attachedCount = $announcement->media()
            ->where('collection_name', 'attachments')
            ->count();

        if ($attachedCount >= self::MAX_ATTACHMENTS) {
            throw new \InvalidArgumentException('Máximo 5 adjuntos por aviso.');
        }

        return $announcement->addMedia($file)
            ->toMediaCollection('attachments');
    }

    /**
     * Remove an attachment from the announcement's `attachments` collection.
     *
     * The media MUST belong to this announcement's attachments collection — a
     * mismatched or foreign media id is a 404 (never deletes another entity's file).
     *
     * @throws ModelNotFoundException when the media is not an attachment of this announcement.
     */
    public function detachFile(Announcement $announcement, string $mediaId): void
    {
        $media = $announcement->media()
            ->where('id', $mediaId)
            ->where('collection_name', 'attachments')
            ->first();

        if ($media === null) {
            throw new ModelNotFoundException('Adjunto no encontrado.');
        }

        $media->delete();
    }

    /**
     * Fan out the "announcement published" notification to the resolved audience.
     *
     * Channel matrix (§12.6): normal → push/in-app; important → push + designed email.
     * data.urgent bypasses quiet hours. An explicit channel_email flag adds the email
     * regardless of priority. SMS is disabled for now (see channelsFor()).
     *
     * The email goes through the TEMPLATE channel (blade_email): email_template names
     * the designed Blade view and email_context carries NON-NULL render data (title,
     * a plain-text body excerpt, the priority label, the humanized audience label and
     * the frontend announcement URL). emailBaseUrl is added downstream by
     * BladeEmailChannel — do NOT add it here.
     */
    private function dispatchPublishedNotifications(Announcement $announcement): void
    {
        $data = [
            'click_action' => '/announcement',
            'announcement_id' => $announcement->id,
            'category' => NotificationCategory::ANNOUNCEMENTS,
            'email_template' => 'announcement-published',
            'email_context' => [
                'title' => $announcement->title,
                'bodyText' => mb_substr(strip_tags($announcement->content), 0, 600),
                'priorityLabel' => $announcement->priority?->name ?? 'Normal',
                'audienceLabel' => $this->audienceLabeler->label(
                    $announcement->target_type,
                    $announcement->target_selector,
                ),
                'announcementUrl' => rtrim((string) config('app.frontend_url'), '/').'/announcement',
            ],
        ];

        if ($announcement->announcement_priority_id === AnnouncementPriority::URGENT) {
            $data['urgent'] = true;
        }

        $this->notifyUsers(
            $this->audienceUserIds($announcement),
            $announcement->title,
            mb_substr(strip_tags($announcement->content), 0, 250),
            $data,
            inApp: true,
            channels: $this->channelsFor($announcement),
        );
    }

    /**
     * Cross-post a freshly published announcement into the "Avisos oficiales"
     * system chat channel via ChatService::postSystemMessage — which deliberately
     * does NOT notify the channel audience (the announcement dispatch above
     * already did; notifying here again would duplicate push/in-app).
     *
     * Best-effort: ANY failure is logged and swallowed so the cross-post can
     * never break the publish flow.
     */
    private function postToOfficialChatChannel(Announcement $announcement): void
    {
        try {
            if ($announcement->author_user_id === null) {
                Log::warning('Failed to cross-post announcement to official chat channel', [
                    'announcement_id' => $announcement->id,
                    'reason' => 'announcement has no author_user_id',
                ]);

                return;
            }

            $chatService = app(ChatService::class);
            $channel = $chatService->findOfficialChannel();

            if ($channel === null) {
                Log::warning('Failed to cross-post announcement to official chat channel', [
                    'announcement_id' => $announcement->id,
                    'reason' => 'official channel not found for tenant',
                ]);

                return;
            }

            $body = $announcement->title."\n\n".$announcement->content;
            $bodyHtml = $announcement->content_html
                ? '<p><strong>'.e($announcement->title).'</strong></p>'.$announcement->content_html
                : null;

            $chatService->postSystemMessage(
                $channel,
                $announcement->author_user_id,
                $body,
                $bodyHtml,
                $announcement->getMedia('attachments')->all(),
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to cross-post announcement to official chat channel', [
                'announcement_id' => $announcement->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify the users who already read the announcement that it changed (§12.6).
     */
    private function notifyReadersOfEdit(Announcement $announcement): void
    {
        $readerIds = $announcement->reads()
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();

        $this->notifyUsers(
            $readerIds,
            'Aviso editado: '.$announcement->title,
            mb_substr(strip_tags($announcement->content), 0, 250),
            [
                'click_action' => '/announcement',
                'announcement_id' => $announcement->id,
                'category' => NotificationCategory::ANNOUNCEMENTS,
            ],
        );
    }

    /**
     * @return string[]
     */
    private function channelsFor(Announcement $announcement): array
    {
        $channels = ['log'];

        $isImportantOrUrgent = in_array($announcement->announcement_priority_id, [
            AnnouncementPriority::IMPORTANT,
            AnnouncementPriority::URGENT,
        ], true);

        if ($isImportantOrUrgent || $announcement->channel_email) {
            // Designed HTML email via the template channel (email_template +
            // email_context are set in dispatchPublishedNotifications).
            $channels[] = 'blade_email';
        }

        // SMS intentionally disabled for now. The channel_sms column, SmsChannel and
        // the dispatcher 'sms' map entry stay intact — re-add the branch here (and the
        // channel_sms=true line in publish()) to re-enable urgent SMS.

        return $channels;
    }
}
