<?php

declare(strict_types=1);

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Reports\Services\IndicatorSnapshotService;
use Modules\Reports\Tests\Support\WithReportsFixtures;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * REQ-RPT-009 (MANDATORY) -- tenant A's exported file never contains a row
 * originating from tenant B, for every one of the 3 mapped models.
 */
final class ExportTenantIsolationTest extends TestCase
{
    use RefreshDatabase;
    use WithReportsFixtures;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_screening_and_screening_indicator_exports_never_leak_across_tenants(): void
    {
        $this->seedPlatformRegistry();
        [$tenantA, $tokenAAdmin] = $this->administeredTenant('Organization A');
        [$tenantB, $tokenBAdmin] = $this->administeredTenant('Organization B');
        $version = $this->defaultInstrumentVersion();

        $fxA = $this->inTenant($tenantA, fn () => $this->makeChildVisit(570));
        $fxB = $this->inTenant($tenantB, fn () => $this->makeChildVisit(570));
        $this->applyAndComplete($tokenAAdmin, $tenantA, $fxA['visit'], $this->g10RedAnswers($version));
        $this->applyAndComplete($tokenBAdmin, $tenantB, $fxB['visit'], $this->g10RedAnswers($version));

        // CURP is nullable on the Child factory (often blank); the child's
        // first_name is a required, Faker-random field per tenant -- a
        // reliable, always-present identity marker for this isolation check.
        $childAFirstName = $this->inTenant($tenantA, fn () => $fxA['child']->fresh()->first_name);
        $childBFirstName = $this->inTenant($tenantB, fn () => $fxB['child']->fresh()->first_name);

        $tokenAExport = $this->tokenWithAbilities($tenantA, ['report.screening']);

        $response = $this->tenantRequest($tokenAExport, $tenantA)
            ->get('/api/customReport?_model=Screening&_type=xlsx&_title=nominal')
            ->assertOk();

        $rows = $this->readXlsxRows($response->baseResponse);
        $flatContent = (string) json_encode($rows);

        $this->assertStringContainsString($childAFirstName, $flatContent);
        $this->assertStringNotContainsString($childBFirstName, $flatContent);

        // ScreeningIndicator side, same isolation guarantee.
        $this->inTenant($tenantA, fn () => app(IndicatorSnapshotService::class)->buildPeriod(now()->format('Y-m')));
        $this->inTenant($tenantB, fn () => app(IndicatorSnapshotService::class)->buildPeriod(now()->format('Y-m')));

        $tokenAIndicatorExport = $this->tokenWithAbilities($tenantA, ['report.screening_indicator']);
        $indicatorResponse = $this->tenantRequest($tokenAIndicatorExport, $tenantA)
            ->get('/api/customReport?_model=ScreeningIndicator&_type=xlsx&_title=concentrado')
            ->assertOk();

        $indicatorRows = $this->readXlsxRows($indicatorResponse->baseResponse);

        // Tenant A ran with 1 organization-wide rollup screening applied.
        $rollupRows = array_filter($indicatorRows, fn ($row) => in_array('Toda la organización', $row, true));
        $this->assertNotEmpty($rollupRows);
        foreach ($rollupRows as $row) {
            $this->assertSame(1, (int) ($row[2] ?? 0), 'Tenant A rollup must count only its own 1 screening, never tenant B\'s.');
        }
    }

    /**
     * `Excel::download()` returns a BinaryFileResponse -- the xlsx is
     * already a real file on disk; read it directly (getContent() returns
     * false by Symfony design for this response type).
     *
     * @return array<int, array<int, mixed>>
     */
    private function readXlsxRows(Response $response): array
    {
        $this->assertInstanceOf(BinaryFileResponse::class, $response);

        $spreadsheet = IOFactory::load($response->getFile()->getRealPath());

        return $spreadsheet->getActiveSheet()->toArray();
    }
}
