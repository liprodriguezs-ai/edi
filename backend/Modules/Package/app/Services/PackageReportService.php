<?php

declare(strict_types=1);

namespace Modules\Package\Services;

use App\Support\SpreadsheetSafe;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Modules\Package\Models\Package;
use Modules\Package\Models\PackageStatus;

/**
 * Read-only aggregation service for the package aging report and dashboard widget.
 *
 * Every query enters through the Package model (BelongsToTenant), so
 * TenantScope injects the tenant_id automatically.
 *
 * Response shapes:
 *
 *   aging() → {
 *     generated_at: string,
 *     total_pending: int,
 *     buckets: {
 *       d0_2:    { count: int, packages: PackageEntry[] },
 *       d3_7:    { count: int, packages: PackageEntry[] },
 *       d7_plus: { count: int, packages: PackageEntry[] },
 *     }
 *   }
 *
 *   pendingCount() → { total: int, d7_plus: int }
 *
 * @phpstan-type PackageEntry array{id: string, unit_full_identifier: string, carrier: string|null, received_at: string|null, storage_location: string|null, days_pending: int}
 */
class PackageReportService
{
    /**
     * Return the full aging payload for all READY packages, grouped into three
     * age buckets using the tenant's timezone for day boundary calculations.
     *
     * Days are computed via Carbon::diffInDays(received_at → now) — always non-negative
     * (mirrors the collection-report-signed-diffindays fix: we pass the past date as
     * the callee and now() as the argument so the result is always positive).
     *
     * @return array<string, mixed>
     */
    public function aging(): array
    {
        $timezone = $this->resolveTimezone();
        $now = Carbon::now($timezone);

        /** @var Collection<int, Package> $packages */
        $packages = Package::where('package_status_id', PackageStatus::READY)
            ->with(['unit.unitType', 'unit.tower', 'carrier'])
            ->get();

        /** @var array<string, array{count: int, packages: array<int, mixed>}> $buckets */
        $buckets = [
            'd0_2' => ['count' => 0, 'packages' => []],
            'd3_7' => ['count' => 0, 'packages' => []],
            'd7_plus' => ['count' => 0, 'packages' => []],
        ];

        foreach ($packages as $pkg) {
            $days = $this->daysPending($pkg, $timezone, $now);

            /** @var string $bucket */
            $bucket = match (true) {
                $days <= 2 => 'd0_2',
                $days <= 7 => 'd3_7',
                default => 'd7_plus',
            };

            $buckets[$bucket]['count']++;
            $buckets[$bucket]['packages'][] = [
                'id' => $pkg->id,
                'unit_full_identifier' => $pkg->unit?->full_identifier ?? $pkg->unit?->number ?? '',
                'carrier' => $pkg->carrier?->name ?? $pkg->carrier_other,
                'received_at' => $pkg->received_at?->toIso8601String(),
                'storage_location' => $pkg->storage_location,
                'days_pending' => $days,
            ];
        }

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'total_pending' => $packages->count(),
            'buckets' => $buckets,
        ];
    }

    /**
     * Build the enriched, sorted rows consumed by the aging Excel export.
     *
     * Loads all READY packages and maps each to a flat stdClass row whose fields
     * line up 1:1 with PackageReportController::reportColumns(). Reuses the SAME
     * day computation as aging() (resolveTimezone + daysPending) so the aging
     * semantics live in exactly one place. Rows are sorted by days_pending desc
     * (oldest pending first).
     *
     * @return SupportCollection<int, \stdClass>
     */
    public function agingExportRows(): SupportCollection
    {
        $timezone = $this->resolveTimezone();
        $now = Carbon::now($timezone);

        return Package::where('package_status_id', PackageStatus::READY)
            ->with(['unit.unitType', 'unit.tower', 'carrier', 'status'])
            ->get()
            ->map(function (Package $pkg) use ($now, $timezone): \stdClass {
                $row = new \stdClass;
                $row->unit_full_identifier = $pkg->unit?->full_identifier ?? $pkg->unit?->number ?? '';
                // Free-text / guard-entered fields — de-fang formula-leading
                // values before they land in an .xlsx cell (plan 059).
                $row->carrier = SpreadsheetSafe::cell($pkg->carrier?->name ?? $pkg->carrier_other ?? '');
                $row->tracking_number = SpreadsheetSafe::cell($pkg->tracking_number ?? '');
                $row->description = SpreadsheetSafe::cell($pkg->description ?? '');
                $row->storage_location = SpreadsheetSafe::cell($pkg->storage_location ?? '');
                $row->received_at_label = $pkg->received_at
                    ? Carbon::parse($pkg->received_at)->setTimezone($timezone)->format('d/m/Y H:i')
                    : '';
                $row->days_pending = $this->daysPending($pkg, $timezone, $now);
                $row->status_label = $pkg->status?->name ?? 'Listo';

                return $row;
            })
            ->sortByDesc('days_pending')
            ->values();
    }

    /**
     * Tenant-configured timezone, falling back to the Mexico City default.
     */
    private function resolveTimezone(): string
    {
        return (string) (tenant('timezone') ?: 'America/Mexico_City');
    }

    /**
     * Non-negative days a package has been pending.
     *
     * diffInDays(past → future) is always non-negative because received_at is the
     * callee and now() the argument (mirrors the collections-report-signed-diffindays
     * fix — never let Carbon return a signed result here).
     */
    private function daysPending(Package $pkg, string $timezone, Carbon $now): int
    {
        $receivedAt = Carbon::parse($pkg->received_at)->setTimezone($timezone);

        return (int) $receivedAt->diffInDays($now);
    }

    /**
     * Lightweight count for the dashboard widget. Two COUNT queries, no JOIN.
     *
     * d7_plus = packages with status READY and received_at 8+ days ago.
     * Uses PHP-side date math (no raw SQL) so it works on both PostgreSQL and SQLite.
     *
     * @return array{total: int, d7_plus: int}
     */
    public function pendingCount(): array
    {
        // Cutoff: packages received 8 or more days ago are in the critical bucket.
        $cutoff = now()->subDays(8)->toDateTimeString();

        $total = Package::where('package_status_id', PackageStatus::READY)->count();
        $d7plus = Package::where('package_status_id', PackageStatus::READY)
            ->where('received_at', '<=', $cutoff)
            ->count();

        return [
            'total' => $total,
            'd7_plus' => $d7plus,
        ];
    }
}
