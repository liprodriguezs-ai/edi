<?php

declare(strict_types=1);

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Appointment\Models\Appointment;
use Modules\ChildRecord\Models\BirthRecord;
use Modules\ChildRecord\Models\Caregiver;
use Modules\ChildRecord\Models\Child;
use Modules\ChildRecord\Models\ChildCaregiver;
use Modules\Nutrition\Database\Seeders\NutritionSeeder;
use Modules\Nutrition\Models\NutritionalAssessment;
use Modules\Nutrition\Models\NutritionalStatus;
use Modules\Reports\Tests\Support\WithReportsFixtures;
use Modules\Screening\Models\Screening;
use Modules\Screening\Models\ScreeningStatus;
use Modules\Tenant\Models\Tenant;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * S4/S5 — Concentrado EDI and Reporte general: one row per child, the
 * latest + previous counted Prueba EDI (scored/completed, never draft or
 * invalidated), impact flags, the latest nutritional assessment, tenant
 * isolation, health-center filter, permissions, Excel export and a bounded
 * query count.
 */
final class ChildReportsTest extends TestCase
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

    /**
     * A child aged 740 days today with: previous test 160 days ago (yellow,
     * MG impact), last test 80 days ago (red, EN impact), a newer invalidated
     * test (10 days ago) and a newer draft (today) that must both be ignored.
     *
     * @return array{child: Child, healthCenterId: string}
     */
    private function childWithHistory(Tenant $tenant, string $token): array
    {
        $version = $this->defaultInstrumentVersion();
        $today = now()->startOfDay();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisitAt(580, $today->copy()->subDays(160)->format('Y-m-d')));
        /** @var Child $child */
        $child = $fx['child'];

        $this->applyAndComplete($token, $tenant, $fx['visit'], $this->g10YellowAnswers($version));

        $lastVisit = $this->inTenant($tenant, fn () => $this->makeVisitFor($child, $today->copy()->subDays(80)->format('Y-m-d')));
        $this->applyAndComplete($token, $tenant, $lastVisit, $this->g10RedAnswers($version));

        $invalidVisit = $this->inTenant($tenant, fn () => $this->makeVisitFor($child, $today->copy()->subDays(10)->format('Y-m-d')));
        $invalidId = $this->applyAndComplete($token, $tenant, $invalidVisit, $this->g10GreenAnswers($version));
        $this->inTenant($tenant, function () use ($invalidId): void {
            Screening::query()->whereKey($invalidId)->update(['screening_status_id' => ScreeningStatus::INVALIDATED]);
        });

        $draftVisit = $this->inTenant($tenant, fn () => $this->makeVisitFor($child, $today->format('Y-m-d')));
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $draftVisit->id])
            ->assertCreated();

        $this->inTenant($tenant, function () use ($child, $today): void {
            BirthRecord::factory()->create(['child_id' => $child->id, 'gestational_age_weeks' => 39]);

            $caregiver = Caregiver::factory()->create([
                'first_name' => 'Rosa',
                'last_name_paternal' => 'Luna',
                'last_name_maternal' => 'Paz',
                'phone' => '5512345678',
            ]);
            ChildCaregiver::factory()->create([
                'child_id' => $child->id,
                'caregiver_id' => $caregiver->id,
                'is_primary' => true,
            ]);

            $this->makeAssessment($child, $today->copy()->subDays(30)->format('Y-m-d'), 10000, 800, NutritionalStatus::OBESITY);
            $this->makeAssessment($child, $today->format('Y-m-d'), 11250, 845, NutritionalStatus::NORMAL_WEIGHT);

            Appointment::factory()->create([
                'child_id' => $child->id,
                'health_center_id' => $child->health_center_id,
                'starts_at' => now()->addDays(40)->setTime(10, 0),
                'ends_at' => now()->addDays(40)->setTime(10, 30),
            ]);
        });

        return ['child' => $child, 'healthCenterId' => $fx['healthCenter']->id];
    }

    private function makeAssessment(Child $child, string $measuredOn, int $weightGrams, int $heightMm, string $weightForHeightStatusId): void
    {
        $assessment = new NutritionalAssessment;
        $assessment->forceFill([
            'child_id' => $child->id,
            'measured_on' => $measuredOn,
            'age_months' => 24,
            'weight_grams' => $weightGrams,
            'height_mm' => $heightMm,
            'weight_for_height_status_id' => $weightForHeightStatusId,
            'weight_for_age_status_id' => NutritionalStatus::NORMAL_WEIGHT,
            'height_for_age_status_id' => NutritionalStatus::NORMAL_STATURE,
        ]);
        $assessment->save();
    }

    private function seedAll(): void
    {
        $this->seedPlatformRegistry();
        (new NutritionSeeder)->run();
    }

    public function test_concentrate_returns_one_row_per_child_with_previous_and_last_counted_test(): void
    {
        $this->seedAll();
        [$tenant, $token] = $this->administeredTenant();
        $fx = $this->childWithHistory($tenant, $token);

        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/reports/edi-concentrate')
            ->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertSame($fx['child']->id, $row['id']);
        $this->assertSame(39, $row['gestational_age_weeks']);

        $this->assertSame(now()->subDays(80)->format('Y-m-d'), $row['last_screening']['date']);
        $this->assertSame('red', $row['last_screening']['result']['key']);
        $this->assertSame('Rojo', $row['last_screening']['result']['name']);
        $this->assertTrue($row['last_screening']['impacts']['EN']);
        $this->assertFalse($row['last_screening']['impacts']['MG']);

        $this->assertSame(now()->subDays(160)->format('Y-m-d'), $row['previous_screening']['date']);
        $this->assertSame('yellow', $row['previous_screening']['result']['key']);
        $this->assertTrue($row['previous_screening']['impacts']['MG']);
        $this->assertFalse($row['previous_screening']['impacts']['EN']);
        $this->assertFalse($row['previous_screening']['impacts']['FRB']);

        $this->assertSame('Rosa Luna Paz', $row['caregiver_name']);
        $this->assertSame('5512345678', $row['caregiver_phone']);
        $this->assertSame(now()->addDays(40)->format('Y-m'), $row['next_evaluation']);

        $this->assertSame(now()->format('Y-m-d'), $row['nutrition']['measured_on']);
        $this->assertSame(11250, $row['nutrition']['weight_grams']);
        $this->assertSame('normal_weight', $row['nutrition']['weight_for_height_status']['key']);
        $this->assertSame('Peso normal', $row['nutrition']['weight_for_height_status']['name']);
        $this->assertSame('normal_stature', $row['nutrition']['height_for_age_status']['key']);

        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_general_report_lists_children_with_latest_measurement_and_tests(): void
    {
        $this->seedAll();
        [$tenant, $token] = $this->administeredTenant();
        $fx = $this->childWithHistory($tenant, $token);

        // A child without any test or measurement still appears in the census.
        $plain = $this->inTenant($tenant, fn () => $this->makeChildVisit(400)['child']);

        $rows = collect($this->tenantRequest($token, $tenant)
            ->getJson('/api/reports/general')
            ->assertOk()
            ->json('data'))->keyBy('id');

        $this->assertCount(2, $rows);

        $row = $rows[$fx['child']->id];
        $this->assertSame(now()->format('Y-m-d'), $row['nutrition']['measured_on']);
        $this->assertSame(845, $row['nutrition']['height_mm']);
        $this->assertSame('red', $row['last_screening']['result']['key']);
        $this->assertSame('yellow', $row['previous_screening']['result']['key']);
        $this->assertSame('Rosa Luna Paz', $row['caregiver_name']);

        $plainRow = $rows[$plain->id];
        $this->assertNull($plainRow['nutrition']);
        $this->assertNull($plainRow['last_screening']);
        $this->assertNull($plainRow['previous_screening']);
    }

    public function test_reports_are_tenant_isolated_and_filter_by_health_center(): void
    {
        $this->seedAll();
        [$tenantA, $tokenA] = $this->administeredTenant('Organization A');
        [$tenantB, $tokenB] = $this->administeredTenant('Organization B');
        $version = $this->defaultInstrumentVersion();

        $fxA1 = $this->inTenant($tenantA, fn () => $this->makeChildVisit(570));
        $fxA2 = $this->inTenant($tenantA, fn () => $this->makeChildVisit(570));
        $fxB = $this->inTenant($tenantB, fn () => $this->makeChildVisit(570));
        $this->applyAndComplete($tokenA, $tenantA, $fxA1['visit'], $this->g10GreenAnswers($version));
        $this->applyAndComplete($tokenA, $tenantA, $fxA2['visit'], $this->g10GreenAnswers($version));
        $this->applyAndComplete($tokenB, $tenantB, $fxB['visit'], $this->g10GreenAnswers($version));

        foreach (['/api/reports/edi-concentrate', '/api/reports/general'] as $endpoint) {
            $ids = collect($this->tenantRequest($tokenA, $tenantA)->getJson($endpoint)->assertOk()->json('data'))->pluck('id');
            $this->assertEqualsCanonicalizing([$fxA1['child']->id, $fxA2['child']->id], $ids->all(), $endpoint);

            $filtered = collect($this->tenantRequest($tokenA, $tenantA)
                ->getJson($endpoint.'?health_center_ids[]='.$fxA1['healthCenter']->id)
                ->assertOk()
                ->json('data'))->pluck('id');
            $this->assertSame([$fxA1['child']->id], $filtered->all(), $endpoint);

            // Another tenant's center is not a valid filter value.
            $this->tenantRequest($tokenA, $tenantA)
                ->getJson($endpoint.'?health_center_ids[]='.$fxB['healthCenter']->id)
                ->assertUnprocessable();
        }
    }

    public function test_endpoints_require_their_own_permissions(): void
    {
        $this->seedAll();
        [$tenant] = $this->administeredTenant();
        $token = $this->tokenWithOnly($tenant, ['read.nominal_report']);

        foreach (['edi-concentrate', 'edi-concentrate/export', 'general', 'general/export'] as $path) {
            $this->tenantRequest($token, $tenant)->getJson('/api/reports/'.$path)->assertForbidden();
        }

        $readOnly = $this->tokenWithOnly($tenant, ['read.edi_concentrate']);
        $this->tenantRequest($readOnly, $tenant)->getJson('/api/reports/edi-concentrate')->assertOk();
        $this->tenantRequest($readOnly, $tenant)->get('/api/reports/edi-concentrate/export')->assertForbidden();
    }

    public function test_concentrate_export_returns_xlsx_with_spanish_headers_and_rows(): void
    {
        $this->seedAll();
        [$tenant, $token] = $this->administeredTenant();
        $fx = $this->childWithHistory($tenant, $token);

        $response = $this->tenantRequest($token, $tenant)
            ->get('/api/reports/edi-concentrate/export')
            ->assertOk();

        $rows = $this->readXlsxRows($response->baseResponse);
        $headers = $rows[0];

        $this->assertContains('Nombre(s)', $headers);
        $this->assertContains('Calificación última prueba', $headers);
        $this->assertContains('Última prueba: MG', $headers);
        $this->assertContains('Peso/talla', $headers);
        $this->assertCount(2, $rows);

        $line = array_combine($headers, $rows[1]);
        $this->assertSame('Rojo', $line['Calificación última prueba']);
        $this->assertSame('Amarillo', $line['Calificación prueba anterior']);
        $this->assertSame(now()->subDays(80)->format('d/m/Y'), $line['Fecha última prueba']);
        $this->assertSame('Sí', $line['Última prueba: EN']);
        $this->assertSame('Peso normal', $line['Peso/talla']);
        $this->assertSame(now()->subDays(740)->format('d/m/Y'), $line['Fecha de nacimiento']);
        $this->assertSame($fx['child']->first_name, $line['Nombre(s)']);
    }

    public function test_general_export_returns_xlsx_with_weight_and_height_formatted(): void
    {
        $this->seedAll();
        [$tenant, $token] = $this->administeredTenant();
        $this->childWithHistory($tenant, $token);

        $response = $this->tenantRequest($token, $tenant)
            ->get('/api/reports/general/export')
            ->assertOk();

        $rows = $this->readXlsxRows($response->baseResponse);
        $line = array_combine($rows[0], $rows[1]);

        $this->assertSame('11.3', (string) $line['Peso (kg)']);
        $this->assertSame('84.5', (string) $line['Talla (cm)']);
        $this->assertSame(now()->format('d/m/Y'), $line['Fecha de última medición']);
        $this->assertSame('Talla normal', $line['Talla/edad']);
        $this->assertSame('Rojo', $line['Resultado última prueba EDI']);
    }

    public function test_query_count_does_not_grow_with_the_number_of_children(): void
    {
        $this->seedAll();
        [$tenant, $token] = $this->administeredTenant();
        $version = $this->defaultInstrumentVersion();

        $first = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $this->applyAndComplete($token, $tenant, $first['visit'], $this->g10GreenAnswers($version));

        // Warm-up: the first request loads cached catalogs (permissions,
        // development areas); the comparison below must only see per-row cost.
        $this->tenantRequest($token, $tenant)->getJson('/api/reports/edi-concentrate')->assertOk();
        $this->tenantRequest($token, $tenant)->getJson('/api/reports/general')->assertOk();

        $single = [
            'edi' => $this->countQueries(fn () => $this->tenantRequest($token, $tenant)->getJson('/api/reports/edi-concentrate')->assertOk()),
            'general' => $this->countQueries(fn () => $this->tenantRequest($token, $tenant)->getJson('/api/reports/general')->assertOk()),
        ];

        foreach (range(1, 3) as $ignored) {
            $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
            $this->applyAndComplete($token, $tenant, $fx['visit'], $this->g10YellowAnswers($version));
        }

        $many = [
            'edi' => $this->countQueries(fn () => $this->tenantRequest($token, $tenant)->getJson('/api/reports/edi-concentrate')->assertOk()->assertJsonCount(4, 'data')),
            'general' => $this->countQueries(fn () => $this->tenantRequest($token, $tenant)->getJson('/api/reports/general')->assertOk()->assertJsonCount(4, 'data')),
        ];

        $this->assertSame($single, $many);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function readXlsxRows(Response $response): array
    {
        $this->assertInstanceOf(BinaryFileResponse::class, $response);

        $spreadsheet = IOFactory::load($response->getFile()->getRealPath());

        return $spreadsheet->getActiveSheet()->toArray();
    }
}
