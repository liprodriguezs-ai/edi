<?php

namespace Modules\Announcements\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Announcements\Models\Announcement;
use Modules\Announcements\Models\AnnouncementPriority;
use Modules\Announcements\Models\AnnouncementStatus;
use Modules\Announcements\Services\AnnouncementReadStatsService;
use Modules\Announcements\Services\AnnouncementService;
use Orion\Http\Requests\Request as OrionRequest;
use QCH\Core\Http\Controllers\Controller;
use QCH\Core\Models\Role;

class AnnouncementController extends Controller
{
    protected $model = Announcement::class;

    protected $keyForPermission = 'announcement';

    public function includes(): array
    {
        return ['type', 'priority', 'status', 'authorUser', 'reads', 'media'];
    }

    /**
     * Relations allowed to be aggregated. The list view requests the reads COUNT
     * (aggregates: [{relation:'reads', type:'count'}] → reads_count) instead of eager
     * loading every AnnouncementRead row just to display a number.
     */
    public function aggregates(): array
    {
        return ['reads'];
    }

    public function filterableBy(): array
    {
        return [
            'announcement_type_id',
            'announcement_priority_id',
            'announcement_status_id',
        ];
    }

    public function sortableBy(): array
    {
        return ['title', 'publish_at', 'expires_at', 'created_at'];
    }

    public function searchableBy(): array
    {
        return ['title', 'content'];
    }

    /**
     * Announcements are always born DRAFT with the authenticated user as author:
     * the client can never choose the status (transitions go through
     * publish/archive) nor spoof authorship (§12.2 / §12.3.1).
     */
    protected function performStore(OrionRequest $request, Model $entity, array $attributes): void
    {
        $data = $request->validated();
        unset($data['announcement_status_id'], $data['author_user_id'], $data['edited_at']);

        $entity->fill($data);
        $entity->announcement_status_id = AnnouncementStatus::DRAFT;
        $entity->author_user_id = auth()->guard('sanctum')->id();

        // Rich text (dual field): content_html is NOT mass-assignable — sanitize it
        // server-side and derive the plain-text content (search + notification excerpts).
        app(AnnouncementService::class)->applyRichTextContent($entity, $data);

        $entity->save();
    }

    /**
     * §12.2: only the author edits their announcement (President/Administrator
     * may edit any). Editing a published announcement stamps edited_at and
     * notifies prior readers (delegated to the service).
     */
    protected function performUpdate(OrionRequest $request, Model $entity, array $attributes): void
    {
        /** @var Announcement $entity */
        $this->assertCanManage($entity);

        $data = $request->validated();
        unset($data['announcement_status_id'], $data['author_user_id'], $data['edited_at']);

        app(AnnouncementService::class)->update($entity, $data);
    }

    /**
     * §12.2: "Eliminar aviso propio" for any creator role; deleting SOMEONE
     * ELSE'S announcement is President/Administrator only.
     */
    protected function performDestroy(Model $entity): void
    {
        /** @var Announcement $entity */
        $this->assertCanManage($entity);

        $entity->delete();
    }

    /**
     * POST /announcement/{id}/publish
     * Transition announcement from draft → published (or scheduled when
     * publish_at is in the future — the module cron activates it).
     */
    public function publish(string $id): JsonResponse
    {
        $this->authorize('update.announcement', Announcement::class);

        $announcement = Announcement::findOrFail($id);
        $this->assertCanManage($announcement);

        $service = app(AnnouncementService::class);
        $published = $service->publish($announcement);

        return response()->json(['data' => $published]);
    }

    /**
     * POST /announcement/{id}/archive
     * Archive an announcement (never delete). Status becomes archived.
     */
    public function archive(string $id): JsonResponse
    {
        $this->authorize('update.announcement', Announcement::class);

        $announcement = Announcement::findOrFail($id);
        $this->assertCanManage($announcement);

        $service = app(AnnouncementService::class);
        $archived = $service->archive($announcement);

        return response()->json(['data' => $archived]);
    }

    /**
     * POST /announcement/{id}/read
     * Mark announcement as read by the authenticated user.
     */
    public function read(string $id, Request $request): JsonResponse
    {
        $announcement = Announcement::findOrFail($id);
        $userId = $request->user()->id;

        $service = app(AnnouncementService::class);
        $readRecord = $service->markRead($announcement, $userId);

        return response()->json(['data' => $readRecord]);
    }

    /**
     * GET /announcement/{id}/metrics
     * Read metrics for an announcement: "Visto por 45 de 77" (§12.3.2 / §12.3.4).
     */
    public function readMetrics(string $id): JsonResponse
    {
        $this->authorize('read.announcement', Announcement::class);

        $announcement = Announcement::findOrFail($id);
        $service = app(AnnouncementService::class);

        return response()->json([
            'data' => [
                'reads_count' => $announcement->reads()->count(),
                'audience_count' => count($service->audienceUserIds($announcement)),
            ],
        ]);
    }

    /**
     * GET /announcement/{id}/read-summary
     * Manager-only (update.announcement + per-record ownership) aggregate read
     * counts over the announcement's FULL audience: target_units_count,
     * target_people_count, read_people_count, unread_people_count, read_percentage.
     * Recipients are resolved LIVE from the target — no snapshot table.
     */
    public function readSummary(string $id): JsonResponse
    {
        $this->authorize('update.announcement', Announcement::class);

        $announcement = Announcement::findOrFail($id);
        $this->assertCanManage($announcement);

        return response()->json(app(AnnouncementReadStatsService::class)->summary($announcement));
    }

    /**
     * GET /announcement/{id}/readers
     * Manager-only (update.announcement + per-record ownership) FLAT, server-paginated
     * list of the announcement's recipients WITH read status (includes non-readers).
     * Query params: status=all|read|unread (default all), search (matches resident name
     * OR unit, case-insensitive), page, per_page (default 20, max 100). Returns an
     * Orion-style { data, meta } envelope.
     */
    public function readers(string $id, Request $request): JsonResponse
    {
        $this->authorize('update.announcement', Announcement::class);

        $announcement = Announcement::findOrFail($id);
        $this->assertCanManage($announcement);

        $status = (string) $request->query('status', 'all');
        if (! in_array($status, ['all', 'read', 'unread'], true)) {
            $status = 'all';
        }

        $searchRaw = $request->query('search');
        $search = is_string($searchRaw) ? trim($searchRaw) : '';
        $search = $search === '' ? null : $search;

        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $page = max(1, (int) $request->integer('page', 1));

        return response()->json(
            app(AnnouncementReadStatsService::class)->readers($announcement, $status, $search, $page, $perPage)
        );
    }

    /**
     * POST /announcement/{id}/media
     * Upload a file attachment to an announcement (up to 5, max 10 MB each).
     */
    public function uploadMedia(string $id, Request $request): JsonResponse
    {
        $this->authorize('update.announcement', Announcement::class);

        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,gif,mp4,mp3,doc,docx,xls,xlsx', 'max:10240'],
        ]);

        $announcement = Announcement::findOrFail($id);
        $this->assertCanManage($announcement);

        try {
            $media = app(AnnouncementService::class)->attachFile($announcement, $request->file('file'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $media], 201);
    }

    /**
     * DELETE /announcement/{id}/media/{mediaId}
     * Remove an attachment from an announcement. Manager-only (update.announcement)
     * with the per-record ownership check; the media must belong to this
     * announcement's attachments collection (404 otherwise).
     */
    public function deleteMedia(string $id, string $mediaId): JsonResponse
    {
        $this->authorize('update.announcement', Announcement::class);

        $announcement = Announcement::findOrFail($id);
        $this->assertCanManage($announcement);

        app(AnnouncementService::class)->detachFile($announcement, $mediaId);

        return response()->json(['data' => true]);
    }

    /**
     * GET /announcement/board
     * Published, non-expired announcements visible to the authenticated user's
     * audience (unit/board scope, §12.3.3 Alcance). Urgent announcements first.
     * Each item carries reads_count and is_read for the current user (§12.3.1).
     */
    public function board(Request $request): JsonResponse
    {
        $publishedStatus = AnnouncementStatus::find(AnnouncementStatus::PUBLISHED);

        if (! $publishedStatus) {
            return response()->json(['data' => []]);
        }

        $userId = (string) $request->user()->id;

        $announcements = Announcement::with(['type', 'priority', 'status', 'media'])
            ->withCount('reads')
            ->withExists(['reads as is_read' => fn ($q) => $q->where('user_id', $userId)])
            ->where('announcement_status_id', $publishedStatus->id)
            ->where(function ($q): void {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now()->toDateString());
            })
            ->where(function ($q): void {
                $q->whereNull('publish_at')
                    ->orWhere('publish_at', '<=', now());
            })
            ->orderByRaw(
                'CASE WHEN announcement_priority_id = ? THEN 0 WHEN announcement_priority_id = ? THEN 1 ELSE 2 END',
                [AnnouncementPriority::URGENT, AnnouncementPriority::IMPORTANT]
            )
            ->orderByDesc('publish_at')
            ->get();

        $visible = app(AnnouncementService::class)
            ->filterVisibleTo($announcements, $userId)
            ->values();

        return response()->json(['data' => $visible]);
    }

    /**
     * POST /announcement/preview
     * Return the number of RESIDENTS the given (target_type, target_selector) would
     * reach — counts people through the unit_user pivot (and board members for
     * 'board'), NOT units — so the compose form can show "llegará a N residentes"
     * before the announcement is saved. An unknown target_type is rejected (422),
     * never silently treated as "all".
     *
     * Gated by create.announcement (NOT read): only roles that actually compose an
     * announcement may probe audience head-counts. read.announcement is held by plain
     * residents/renters, who must not be able to enumerate per-tower/floor/type/feature
     * resident counts through arbitrary selectors.
     */
    public function preview(Request $request): JsonResponse
    {
        $this->authorize('create.announcement', Announcement::class);

        $validated = $request->validate([
            'target_type' => ['nullable', 'string', Rule::in(AnnouncementService::TARGET_TYPES)],
            'target_selector' => ['nullable', 'array'],
        ]);

        $ids = app(AnnouncementService::class)->audienceUserIdsFor(
            $validated['target_type'] ?? 'all',
            $validated['target_selector'] ?? null,
        );

        // Bare {count} (no {data:…} wrapper) is deliberate: AnnouncementResource.preview
        // reads data.count directly.
        return response()->json(['count' => count($ids)]);
    }

    /**
     * Ownership gate (§12.2): the author manages their own announcement; the
     * elevated roles (President / Administrator / Account Owner / Super Admin —
     * or a wildcard token) manage any.
     *
     * @throws AuthorizationException
     */
    private function assertCanManage(Announcement $announcement): void
    {
        $user = auth()->guard('sanctum')->user();

        if ($user === null) {
            throw new AuthorizationException('Unauthorized action.');
        }

        if ($announcement->author_user_id === $user->id) {
            return;
        }

        if ($user->tokenCan('*')) {
            return;
        }

        if ($user->hasRole([
            Role::SLUG_PRESIDENT,
            Role::SLUG_ADMINISTRATOR,
            Role::SLUG_ACCOUNT_OWNER,
            Role::SLUG_SUPER_ADMIN,
        ])) {
            return;
        }

        throw new AuthorizationException('Solo el autor o la administración pueden modificar este aviso.');
    }
}
