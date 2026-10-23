<?php

declare(strict_types=1);

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Reports\Tests\Support\WithReportsFixtures;
use Tests\TestCase;

/**
 * REQ-RPT-004 -- aggregate payloads must contain zero PII at any depth, for
 * a user holding ONLY read.screening_indicator.
 */
final class IndicatorPayloadHasNoPiiTest extends TestCase
{
    use RefreshDatabase;
    use WithReportsFixtures;

    private const FORBIDDEN_KEYS = [
        'first_name', 'last_name_paternal', 'last_name_maternal', 'curp',
        'birth_date', 'child_id', 'notes', 'child', 'name',
    ];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_summary_monthly_series_area_breakdown_and_concentrate_are_pii_free(): void
    {
        $this->seedPlatformRegistry();
        [$tenant] = $this->administeredTenant();
        $version = $this->defaultInstrumentVersion();
        $token = $this->tokenWithOnly($tenant, ['read.screening_indicator']);

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $adminToken = $this->administratorToken($tenant);
        $this->applyAndComplete($adminToken, $tenant, $fx['visit'], $this->g10RedAnswers($version));

        foreach ([
            '/api/reports/indicators/summary',
            '/api/reports/indicators/monthly-series?months=3',
            '/api/reports/indicators/area-breakdown',
            '/api/reports/indicators/concentrate',
        ] as $endpoint) {
            $payload = $this->tenantRequest($token, $tenant)->getJson($endpoint)->assertOk()->json();

            $this->assertNoForbiddenKeys($payload, $endpoint);
        }
    }

    /**
     * @param  mixed  $value
     */
    private function assertNoForbiddenKeys($value, string $context, string $path = ''): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $currentPath = $path === '' ? (string) $key : "{$path}.{$key}";

                if (is_string($key) && in_array($key, self::FORBIDDEN_KEYS, true)) {
                    $this->fail("PII key '{$key}' found at '{$currentPath}' in {$context}.");
                }

                $this->assertNoForbiddenKeys($item, $context, $currentPath);
            }
        }
    }
}
