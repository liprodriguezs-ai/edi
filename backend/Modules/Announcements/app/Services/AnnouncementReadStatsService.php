<?php

namespace Modules\Announcements\Services;

use App\Models\User;
use App\Notifications\Concerns\NotifiesResidents;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Modules\Announcements\Models\Announcement;
use Modules\Announcements\Models\AnnouncementRead;
use Modules\Collections\Services\TargetResolver;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitUser;

/**
 * Read analytics for an announcement, resolved LIVE from its target (never a
 * snapshot). Split into two cheap, independent reads:
 *
 *  - summary(): the five aggregate counts over the FULL audience — COUNT(DISTINCT)
 *    queries only, no row hydration.
 *  - readers(): a FLAT, server-paginated list of the announcement's RECIPIENTS
 *    (people) WITH their read status, INCLUDING non-readers (LEFT JOIN
 *    announcement_read). Filtered (status/search) and ordered in SQL, then only the
 *    current page is hydrated.
 *
 * Recipient resolution mirrors AnnouncementService::audienceUserIdsFor:
 *  - 'board' → current board members (unit=null, "Mesa directiva"),
 *  - 'all'   → every active resident (unit_user end_date null), skipping the
 *              Collections billability filter so the set matches the notified set,
 *  - other   → TargetResolver units mapped to their active residents.
 */
class AnnouncementReadStatsService
{
    use NotifiesResidents;

    /**
     * Map the fixed CENTRAL unit_relationship UUIDs to the English keys the API
     * contract exposes. Any non-standard relationship falls back to its label.
     *
     * @var array<string, string>
     */
    private const RELATIONSHIP_KEYS = [
        UnitRelationship::OWNER => 'owner',
        UnitRelationship::CO_OWNER => 'co_owner',
        UnitRelationship::RENTER => 'renter',
        UnitRelationship::FAMILY_RESIDENT => 'family_resident',
        UnitRelationship::BILLING_CONTACT => 'billing_contact',
    ];

    public function __construct(private readonly TargetResolver $targetResolver) {}

    /**
     * The five aggregate counts over the full audience.
     *
     * @return array<string, mixed>
     */
    public function summary(Announcement $announcement): array
    {
        $targetType = $announcement->target_type ?? 'all';

        if ($targetType === 'board') {
            $memberIds = $this->currentBoardMemberUserIds();
            $peopleCount = count($memberIds);
            $readCount = $peopleCount === 0
                ? 0
                : AnnouncementRead::query()
                    ->where('announcement_id', $announcement->id)
                    ->whereIn('user_id', $memberIds)
                    ->distinct()
                    ->count('user_id');

            return $this->counts(0, $peopleCount, $readCount);
        }

        $unitIds = null;
        if ($targetType !== 'all') {
            $resolved = $this->targetResolver->resolveUnitIds($targetType, $announcement->target_selector);

            if ($resolved->isEmpty()) {
                return $this->counts(0, 0, 0);
            }

            $unitIds = $resolved->all();
        }

        $recipientPivot = fn (): Builder => UnitUser::query()
            ->whereNull('end_date')
            ->when($targetType !== 'all', fn (Builder $q): Builder => $q->whereIn('unit_id', $unitIds));

        $peopleCount = $recipientPivot()->distinct()->count('user_id');

        if ($peopleCount === 0) {
            return $this->counts(0, 0, 0);
        }

        $unitsCount = $recipientPivot()->distinct()->count('unit_id');

        $readCount = AnnouncementRead::query()
            ->where('announcement_id', $announcement->id)
            ->whereIn('user_id', $recipientPivot()->select('user_id'))
            ->distinct()
            ->count('user_id');

        return $this->counts($unitsCount, $peopleCount, $readCount);
    }

    /**
     * A flat, server-paginated list of recipients with read status.
     *
     * @param  string  $status  one of all|read|unread
     * @return array<string, mixed>  Orion-style { data: [...], meta: {...} }
     */
    public function readers(Announcement $announcement, string $status, ?string $search, int $page, int $perPage): array
    {
        $targetType = $announcement->target_type ?? 'all';

        if ($targetType === 'board') {
            return $this->boardReaders($announcement, $status, $search, $page, $perPage);
        }

        $unitIds = null;
        if ($targetType !== 'all') {
            $resolved = $this->targetResolver->resolveUnitIds($targetType, $announcement->target_selector);

            if ($resolved->isEmpty()) {
                return $this->emptyReaders($perPage);
            }

            $unitIds = $resolved->all();
        }

        $unitUserTable = (new UnitUser)->getTable();
        $unitTable = (new Unit)->getTable();
        $towerTable = (new Tower)->getTable();
        $userTable = (new User)->getTable();
        $readTable = (new AnnouncementRead)->getTable();
        $tenantId = tenant()?->getTenantKey();

        // One flat query: active unit_user rows of the target units, joined to their
        // unit/tower (ordering + unit search) and user (name ordering + search). The
        // read state is LEFT JOINed for the status filter only; read_at itself is read
        // back through the model cast on the page (below) to stay timezone-correct.
        $query = UnitUser::query()
            ->whereNull("{$unitUserTable}.end_date")
            ->when($targetType !== 'all', fn (Builder $q): Builder => $q->whereIn("{$unitUserTable}.unit_id", $unitIds))
            ->join($unitTable, "{$unitTable}.id", '=', "{$unitUserTable}.unit_id")
            ->leftJoin($towerTable, "{$towerTable}.id", '=', "{$unitTable}.tower_id")
            ->join("{$userTable} as reader_user", 'reader_user.id', '=', "{$unitUserTable}.user_id")
            ->when($status !== 'all', function (Builder $q) use ($status, $announcement, $readTable, $unitUserTable, $tenantId): void {
                $q->leftJoin($readTable, function (JoinClause $join) use ($announcement, $readTable, $unitUserTable, $tenantId): void {
                    $join->on("{$readTable}.user_id", '=', "{$unitUserTable}.user_id")
                        ->where("{$readTable}.announcement_id", '=', $announcement->id)
                        ->whereNull("{$readTable}.deleted_at");

                    if ($tenantId !== null) {
                        $join->where("{$readTable}.tenant_id", '=', $tenantId);
                    }
                });

                if ($status === 'read') {
                    $q->whereNotNull("{$readTable}.read_at");
                } else {
                    $q->whereNull("{$readTable}.read_at");
                }
            })
            ->when($search !== null && $search !== '', function (Builder $q) use ($search, $unitTable, $towerTable): void {
                $like = '%'.$this->escapeLike(mb_strtolower($search)).'%';
                $q->where(function (Builder $sub) use ($like, $unitTable, $towerTable): void {
                    $sub->whereRaw('LOWER(reader_user.name) LIKE ?', [$like])
                        ->orWhereRaw("LOWER({$unitTable}.number) LIKE ?", [$like])
                        ->orWhereRaw("LOWER(COALESCE({$towerTable}.name, '')) LIKE ?", [$like]);
                });
            })
            ->orderBy("{$unitTable}.tower_id")
            ->orderBy("{$unitTable}.number")
            ->orderBy('reader_user.name')
            ->orderBy("{$unitUserTable}.id")
            ->select("{$unitUserTable}.*");

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        // Hydrate only the page's rows: unit (+tower/type for full_identifier),
        // relationship label, and user name — no N+1.
        $paginator->getCollection()->load(['unit.tower', 'unit.unitType', 'unitRelationship', 'user:id,name']);

        $pageUserIds = $paginator->getCollection()->pluck('user_id')->unique()->values()->all();

        $readAtByUser = AnnouncementRead::query()
            ->where('announcement_id', $announcement->id)
            ->whereIn('user_id', $pageUserIds)
            ->get(['user_id', 'read_at'])
            ->keyBy('user_id');

        $data = $paginator->getCollection()->map(function (UnitUser $row) use ($readAtByUser): array {
            $read = $readAtByUser->get($row->user_id);

            return [
                'user' => [
                    'id' => $row->user_id,
                    'name' => $row->user?->name ?? '—',
                ],
                'unit' => [
                    'id' => $row->unit?->id,
                    'full_identifier' => $row->unit?->full_identifier ?? '—',
                ],
                'relationship' => $this->relationshipKey($row),
                'read' => $read !== null,
                'read_at' => $read?->read_at?->toIso8601ZuluString(),
            ];
        })->all();

        return $this->paginatedEnvelope($data, $paginator);
    }

    /**
     * 'board' recipients are specific PEOPLE (current board members). They carry no
     * unit (unit.id null, "Mesa directiva") and are a small set, so the status/search
     * filter and pagination are applied in PHP.
     *
     * @return array<string, mixed>
     */
    private function boardReaders(Announcement $announcement, string $status, ?string $search, int $page, int $perPage): array
    {
        $memberIds = $this->currentBoardMemberUserIds();

        if ($memberIds === []) {
            return $this->emptyReaders($perPage);
        }

        $readAtByUser = AnnouncementRead::query()
            ->where('announcement_id', $announcement->id)
            ->whereIn('user_id', $memberIds)
            ->get(['user_id', 'read_at'])
            ->keyBy('user_id');

        $names = User::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('id', $memberIds)
            ->pluck('name', 'id');

        $needle = ($search !== null && $search !== '') ? mb_strtolower($search) : null;

        $people = collect($memberIds)
            ->map(function (string $userId) use ($readAtByUser, $names): array {
                $read = $readAtByUser->get($userId);

                return [
                    'user' => ['id' => $userId, 'name' => $names->get($userId) ?? '—'],
                    'unit' => ['id' => null, 'full_identifier' => 'Mesa directiva'],
                    'relationship' => 'board_member',
                    'read' => $read !== null,
                    'read_at' => $read?->read_at?->toIso8601ZuluString(),
                ];
            })
            ->filter(function (array $person) use ($status, $needle): bool {
                if ($status === 'read' && ! $person['read']) {
                    return false;
                }

                if ($status === 'unread' && $person['read']) {
                    return false;
                }

                if ($needle !== null) {
                    $haystack = mb_strtolower($person['user']['name'].' '.$person['unit']['full_identifier']);
                    if (! str_contains($haystack, $needle)) {
                        return false;
                    }
                }

                return true;
            })
            ->sortBy('user.name')
            ->values();

        $total = $people->count();
        $items = $people->slice(($page - 1) * $perPage, $perPage)->values()->all();

        return [
            'data' => $items,
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function counts(int $units, int $people, int $read): array
    {
        return [
            'target_units_count' => $units,
            'target_people_count' => $people,
            'read_people_count' => $read,
            'unread_people_count' => $people - $read,
            'read_percentage' => $this->percentage($read, $people),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $data
     * @return array<string, mixed>
     */
    private function paginatedEnvelope(array $data, LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyReaders(int $perPage): array
    {
        return [
            'data' => [],
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $perPage,
                'total' => 0,
            ],
        ];
    }

    private function relationshipKey(UnitUser $resident): ?string
    {
        $id = $resident->unit_relationship_id;

        if ($id !== null && isset(self::RELATIONSHIP_KEYS[$id])) {
            return self::RELATIONSHIP_KEYS[$id];
        }

        return $resident->unitRelationship?->name;
    }

    private function percentage(int $part, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round($part / $total * 100, 1);
    }

    /**
     * Escape LIKE wildcards in user-supplied search so % and _ match literally
     * (Postgres uses backslash as the default LIKE escape character).
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
