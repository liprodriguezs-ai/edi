<?php

namespace Modules\Reports\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use QCH\Core\Support\TenantTime;
use stdClass;

/**
 * Concentrado EDI (legacy /censos/edi-resumen) and Reporte general (legacy
 * /censos/ninosano, census of children under 5) — S4, with the S5
 * nutrition columns. The paginated listing and the Excel export read the
 * SAME filtered query, so the file always matches what the screen shows
 * (all pages).
 */
class ChildReportService
{
    public function __construct(
        private readonly ChildReportQuery $query,
        private readonly ChildReportRowMapper $mapper,
    ) {}

    /**
     * @param  array{health_center_ids?: string[], from?: ?string, to?: ?string, search?: ?string}  $filters
     */
    public function paginateConcentrate(array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        return $this->paginate($this->concentrateQuery($filters), $perPage, $page, fn (stdClass $row) => $this->mapper->concentrate($row));
    }

    /**
     * @param  array{health_center_ids?: string[], from?: ?string, to?: ?string, search?: ?string}  $filters
     */
    public function paginateGeneral(array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        return $this->paginate($this->generalQuery($filters), $perPage, $page, fn (stdClass $row) => $this->mapper->general($row));
    }

    /**
     * @param  array{health_center_ids?: string[], from?: ?string, to?: ?string, search?: ?string}  $filters
     * @return iterable<int, array<string, mixed>>
     */
    public function concentrateRows(array $filters): iterable
    {
        foreach ($this->concentrateQuery($filters)->toBase()->cursor() as $row) {
            yield $this->mapper->concentrate($row);
        }
    }

    /**
     * @param  array{health_center_ids?: string[], from?: ?string, to?: ?string, search?: ?string}  $filters
     * @return iterable<int, array<string, mixed>>
     */
    public function generalRows(array $filters): iterable
    {
        foreach ($this->generalQuery($filters)->toBase()->cursor() as $row) {
            yield $this->mapper->general($row);
        }
    }

    /** Only children with at least one counted Prueba EDI. */
    private function concentrateQuery(array $filters): Builder
    {
        return $this->query->withConcentrateColumns($this->query->base($filters))
            ->whereNotNull('ls.id');
    }

    /** Census of children under 5 years (born after today minus 5 years). */
    private function generalQuery(array $filters): Builder
    {
        $fiveYearsAgo = CarbonImmutable::parse(TenantTime::today())->subYears(5)->format('Y-m-d');

        return $this->query->base($filters)->where('child.birth_date', '>', $fiveYearsAgo);
    }

    /**
     * @param  callable(stdClass): array<string, mixed>  $map
     */
    private function paginate(Builder $query, int $perPage, int $page, callable $map): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->toBase()->paginate($perPage, ['*'], 'page', $page);

        return $paginator->through($map);
    }
}
