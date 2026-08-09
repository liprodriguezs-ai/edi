<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Modules\Projects\Models\CommunityProject;
use Modules\Projects\Models\CommunityProjectStatus;
use QCH\Core\Services\BuildsTenantReports;
use QCH\Core\Services\CachesTenantReports;
use QCH\Core\Support\TenantMoney;

/**
 * Read-only aggregation service for the projects funding-summary report endpoint.
 *
 * Internally (computeFundingSummary() + cachedReport()) money values are
 * minor-unit integers (centavos) — the cache payload keys are unchanged.
 * fundingSummary() maps those ints to Money objects AFTER reading the cache,
 * using the tenant's active currency (TenantMoney::make). Queries enter via
 * CommunityProject (BelongsToTenant) so TenantScope injects tenant_id.
 *
 * ProjectContribution.contributed_amount is the correct column for withSum —
 * confirmed from the model ($fillable and MoneyIntegerCast).
 *
 * CommunityProjectStatus has no "key" column — we derive status_key from the
 * UUID constant map defined in this service.
 */
class ProjectsReportService
{
    use BuildsTenantReports;
    use CachesTenantReports;

    /**
     * UUID → stable key map for CommunityProjectStatus catalog rows.
     *
     * @var array<string, string>
     */
    private const STATUS_KEY_MAP = [
        CommunityProjectStatus::DRAFT => 'draft',
        CommunityProjectStatus::ACTIVE => 'active',
        CommunityProjectStatus::FULLY_FUNDED => 'fully_funded',
        CommunityProjectStatus::EXECUTED => 'executed',
        CommunityProjectStatus::CANCELLED => 'cancelled',
    ];

    /**
     * Return the full funding-summary payload (spec §5.5).
     *
     * N+1 guard: single with('status') + withSum('contributions', 'contributed_amount')
     * — no per-project additional query.
     *
     * @return array<string, mixed>
     */
    public function fundingSummary(): array
    {
        /** @var array<string, mixed> */
        $report = $this->cachedReport(
            'funding_summary',
            [],
            function (): array {
                return $this->computeFundingSummary();
            },
        );

        return [
            'projects' => array_map(fn (array $row): array => [
                'id' => $row['id'],
                'title' => $row['title'],
                'goal' => TenantMoney::make($row['goal_minor']),
                'raised' => TenantMoney::make($row['raised_minor']),
                'pct' => $row['pct'],
                'status_key' => $row['status_key'],
                'status_label' => $row['status_label'],
            ], $report['projects']),
            'totals' => [
                'active_count' => $report['totals']['active_count'],
                'goal' => TenantMoney::make($report['totals']['goal_minor']),
                'raised' => TenantMoney::make($report['totals']['raised_minor']),
                'pct' => $report['totals']['pct'],
            ],
            'by_status' => $report['by_status'],
        ];
    }

    /** @return array<string, mixed> */
    private function computeFundingSummary(): array
    {
        $projects = CommunityProject::query()
            ->with('status:id,name')
            ->withSum('contributions as raised_amount_sum', 'contributed_amount')
            ->get();

        $projectRows = [];
        $totalGoal = 0;
        $totalRaised = 0;
        $activeCount = 0;

        /** @var array<string, array{status_key: string, label: string, count: int}> $byStatus */
        $byStatus = [];

        foreach ($projects as $project) {
            $goalMinor = (int) $project->getRawOriginal('goal_amount');
            $raisedMinor = (int) ($project->raised_amount_sum ?? 0);
            $pct = $this->safePercent($raisedMinor, $goalMinor);

            $statusId = $project->community_project_status_id;
            $statusKey = self::STATUS_KEY_MAP[$statusId] ?? 'unknown';
            $statusLabel = $project->status?->name ?? 'Desconocido';

            $totalGoal += $goalMinor;
            $totalRaised += $raisedMinor;

            if ($statusKey === 'active') {
                $activeCount++;
            }

            $projectRows[] = [
                'id' => $project->id,
                'title' => $project->title,
                'goal_minor' => $goalMinor,
                'raised_minor' => $raisedMinor,
                'pct' => $pct,
                'status_key' => $statusKey,
                'status_label' => $statusLabel,
            ];

            // By-status grouping.
            if (! isset($byStatus[$statusId])) {
                $byStatus[$statusId] = [
                    'status_key' => $statusKey,
                    'label' => $statusLabel,
                    'count' => 0,
                ];
            }
            $byStatus[$statusId]['count']++;
        }

        $totalPct = $this->safePercent($totalRaised, $totalGoal);

        return [
            'projects' => $projectRows,
            'totals' => [
                'active_count' => $activeCount,
                'goal_minor' => $totalGoal,
                'raised_minor' => $totalRaised,
                'pct' => $totalPct,
            ],
            'by_status' => array_values($byStatus),
        ];
    }
}
