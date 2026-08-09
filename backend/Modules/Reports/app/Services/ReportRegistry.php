<?php

namespace Modules\Reports\Services;

use Illuminate\Support\Facades\Cache;

/**
 * ReportRegistry — single source of truth for the allowed report keys and a
 * unified build dispatcher.
 *
 * Both the synchronous controller path and the async GenerateReportJob route
 * through build() so the report catalogue stays in ONE place. All data comes
 * from ReportService (tenant-scoped Eloquent only).
 */
class ReportRegistry
{
    /** Reports the resident own-statement path is allowed to request. */
    public const RESIDENT_KEYS = ['unit_statement'];

    public function __construct(private readonly ReportService $reportService) {}

    /**
     * The full list of report keys this module can generate.
     *
     * @return array<int, string>
     */
    public static function allowedKeys(): array
    {
        return [
            // Existing
            'delinquency',
            'income_statement',
            'account_balances',
            'audit_log',
            'collection_progress',
            // Financial
            'cash_flow',
            'expenses_by_category',
            'annual_fiscal',
            // Collections
            'unit_statement',
            'global_statement',
            'payment_history',
            'credit_balances',
            // Operations
            'access_log_report',
            'staff_report',
            'provider_history',
            'project_progress',
            // Administrative
            'resident_directory',
            'active_board',
            'announcements_sent',
        ];
    }

    public static function isAllowed(string $key): bool
    {
        return in_array($key, self::allowedKeys(), true);
    }

    /**
     * Build the aggregated data array for a report key + config.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    public function build(string $key, array $config): ?array
    {
        return $this->buildRaw($key, $config);
    }

    /**
     * Build with a 5-minute (§15.6 r3) tenant+report+params-scoped cache.
     * The CacheTenancyBootstrapper already prefixes the cache key per tenant, so
     * two different tenants never share a cached payload.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    public function cachedBuild(string $key, array $config): ?array
    {
        $cacheKey = 'report:'.$key.':'.md5(json_encode($config) ?: '');

        try {
            return Cache::remember($cacheKey, 300, fn (): ?array => $this->buildRaw($key, $config));
        } catch (\BadMethodCallException) {
            // El CacheTenancyBootstrapper usa tags y el store local (file) no los
            // soporta — en ese caso se construye sin cache (solo afecta dev).
            return $this->buildRaw($key, $config);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private function buildRaw(string $key, array $config): ?array
    {
        $service = $this->reportService;

        $periodStart = $config['period_start'] ?? now()->startOfMonth()->toDateString();
        $periodEnd = $config['period_end'] ?? now()->endOfMonth()->toDateString();
        $year = (int) ($config['year'] ?? now()->year);

        return match ($key) {
            'delinquency' => $service->delinquency(),
            'income_statement' => $service->incomeStatement($periodStart, $periodEnd),
            'account_balances' => ['data' => $service->accountBalances()],
            // Exports need a plain array (the formatter signature is array
            // $reportData); the paginator stays exclusive to the JSON endpoint.
            'audit_log' => ['data' => $service->auditLog($config, (int) ($config['per_page'] ?? 1000))->items()],
            'collection_progress' => ($config['batch_id'] ?? '') !== ''
                ? $service->collectionProgress($config['batch_id'])
                : throw new \InvalidArgumentException('El reporte de avance de cobranza requiere batch_id.'),
            'cash_flow' => $service->cashFlow($periodStart, $periodEnd),
            'expenses_by_category' => $service->expensesByCategory($periodStart, $periodEnd),
            'annual_fiscal' => $service->annualFiscal($year),
            'unit_statement' => $service->unitStatement($config['unit_id'] ?? ''),
            'global_statement' => $service->globalStatement(),
            'payment_history' => $service->paymentHistory($periodStart, $periodEnd),
            'credit_balances' => $service->creditBalances(),
            'access_log_report' => $service->accessLogReport($periodStart, $periodEnd),
            'staff_report' => $service->staffReport(),
            'provider_history' => $service->providerHistory($periodStart, $periodEnd),
            'project_progress' => $service->projectProgress(),
            'resident_directory' => $service->residentDirectory(),
            'active_board' => $service->activeBoard(),
            'announcements_sent' => $service->announcementsSent($periodStart, $periodEnd),
            default => null,
        };
    }
}
