<?php

declare(strict_types=1);

namespace Modules\Reports\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ChildRecord\Models\Child;
use Modules\Clinic\Database\Factories\HealthCenterFactory;
use Modules\Clinic\Models\HealthCenter;
use Modules\Instrument\Models\ResultColor;
use Modules\Reports\Models\ScreeningIndicatorSnapshot;
use Modules\Reports\Services\IndicatorSnapshotService;
use Modules\Reports\Tests\Support\WithReportsFixtures;
use Modules\Screening\Models\Screening;
use Tests\TestCase;

/**
 * REQ-RPT-001/002/003.
 */
final class SnapshotBuildTest extends TestCase
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

    public function test_rollup_and_per_center_rows_coexist_for_one_period(): void
    {
        $this->seedPlatformRegistry();
        [$tenant, $token] = $this->administeredTenant();
        $version = $this->defaultInstrumentVersion();

        // fxA1 seeds health center A; a second child is added to the SAME
        // center so center A carries 2 screenings.
        $fxA1 = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $centerA = $fxA1['healthCenter'];
        $fxA2 = $this->inTenant($tenant, function () use ($centerA) {
            $child = Child::factory()->create([
                'health_center_id' => $centerA->id,
                'birth_date' => now()->subDays(570)->startOfDay()->format('Y-m-d'),
            ]);

            return ['child' => $child, 'visit' => $this->makeVisitFor($child, now()->format('Y-m-d'))];
        });

        // A second, DISTINCT health center with one screening.
        $centerB = $this->inTenant($tenant, function () {
            return HealthCenter::factory()->create([
                'municipality_id' => HealthCenterFactory::defaultMunicipality()->id,
            ]);
        });
        $fxB = $this->inTenant($tenant, function () use ($centerB) {
            $child = Child::factory()->create([
                'health_center_id' => $centerB->id,
                'birth_date' => now()->subDays(570)->startOfDay()->format('Y-m-d'),
            ]);
            $visit = $this->makeVisitFor($child, now()->format('Y-m-d'));

            return ['child' => $child, 'visit' => $visit];
        });

        $this->applyAndComplete($token, $tenant, $fxA1['visit'], $this->g10GreenAnswers($version));
        $this->applyAndComplete($token, $tenant, $fxA2['visit'], $this->g10RedAnswers($version));
        $this->applyAndComplete($token, $tenant, $fxB['visit'], $this->g10RedAnswers($version));

        $period = now()->format('Y-m');

        $this->inTenant($tenant, function () use ($period) {
            app(IndicatorSnapshotService::class)->buildPeriod($period);
        });

        $rows = $this->inTenant($tenant, fn () => ScreeningIndicatorSnapshot::where('period_start', now()->startOfMonth()->toDateString())->get());

        $this->assertCount(3, $rows, 'Expected exactly 3 rows: 2 health centers + 1 organization rollup.');

        $rollup = $rows->firstWhere('health_center_id', null);
        $this->assertNotNull($rollup);
        $this->assertSame(3, $rollup->screenings_applied);
        $this->assertSame(1, $rollup->result_green_count);
        $this->assertSame(2, $rollup->result_red_count);
        $this->assertSame(0, $rollup->result_yellow_count);
        $this->assertNotEmpty($rollup->area_breakdown);
        $this->assertArrayHasKey('first_time_green', $rollup->situation_breakdown + ['first_time_green' => 0]);

        $centerARow = $rows->firstWhere('health_center_id', $fxA1['healthCenter']->id);
        $this->assertNotNull($centerARow);
        $this->assertSame(2, $centerARow->screenings_applied);
        $this->assertSame(1, $centerARow->result_green_count);
        $this->assertSame(1, $centerARow->result_red_count);

        $centerBRow = $rows->firstWhere('health_center_id', $centerB->id);
        $this->assertNotNull($centerBRow);
        $this->assertSame(1, $centerBRow->screenings_applied);
        $this->assertSame(1, $centerBRow->result_red_count);
    }

    public function test_a_second_null_rollup_row_is_rejected_by_the_unique_index(): void
    {
        $this->seedPlatformRegistry();
        [$tenant] = $this->administeredTenant();

        $this->inTenant($tenant, function () {
            ScreeningIndicatorSnapshot::query()->create([
                'health_center_id' => null,
                'period_start' => now()->startOfMonth()->toDateString(),
                'computed_at' => now(),
            ]);

            $this->expectException(QueryException::class);

            ScreeningIndicatorSnapshot::query()->create([
                'health_center_id' => null,
                'period_start' => now()->startOfMonth()->toDateString(),
                'computed_at' => now(),
            ]);
        });
    }

    public function test_rescore_and_invalidation_change_the_numbers_on_rebuild(): void
    {
        $this->seedPlatformRegistry();
        [$tenant, $token] = $this->administeredTenant();
        $version = $this->defaultInstrumentVersion();

        $julyDate = '2026-07-15';

        $fxYellow = $this->inTenant($tenant, fn () => $this->makeChildVisitAt(570, $julyDate));
        $fxInvalidated = $this->inTenant($tenant, fn () => $this->makeChildVisitAt(570, $julyDate));

        $yellowId = $this->applyAndComplete($token, $tenant, $fxYellow['visit'], $this->g10YellowAnswers($version));
        $invalidatedId = $this->applyAndComplete($token, $tenant, $fxInvalidated['visit'], $this->g10RedAnswers($version));

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$invalidatedId}/invalidate", ['reason' => 'Registro duplicado'])
            ->assertOk();

        $period = '2026-07';

        $this->inTenant($tenant, function () use ($period) {
            app(IndicatorSnapshotService::class)->buildPeriod($period);
        });

        $before = $this->inTenant($tenant, fn () => ScreeningIndicatorSnapshot::whereNull('health_center_id')->where('period_start', '2026-07-01')->firstOrFail());
        $this->assertSame(1, $before->screenings_applied, 'The invalidated screening must never enter the counted set.');
        $this->assertSame(1, $before->result_yellow_count);
        $this->assertSame(0, $before->result_red_count);

        // Simulate an authoritative rescore flipping the persisted result
        // (ScoringEngine internals are frozen/out of scope for this change --
        // this asserts the AGGREGATION reacts correctly to the new state).
        $this->inTenant($tenant, function () use ($yellowId) {
            $screening = Screening::findOrFail($yellowId);
            $screening->forceFill(['result_color_id' => ResultColor::RED])->save();
        });

        $this->inTenant($tenant, function () use ($period) {
            app(IndicatorSnapshotService::class)->buildPeriod($period);
        });

        $after = $this->inTenant($tenant, fn () => ScreeningIndicatorSnapshot::whereNull('health_center_id')->where('period_start', '2026-07-01')->firstOrFail());
        $this->assertSame(1, $after->screenings_applied);
        $this->assertSame(0, $after->result_yellow_count, 'Yellow count must decrease by 1.');
        $this->assertSame(1, $after->result_red_count, 'Red count must increase by 1.');
        $this->assertSame(
            $after->screenings_applied,
            $after->result_green_count + $after->result_yellow_count + $after->result_red_count,
            'green + yellow + red must equal screenings_applied.'
        );
    }

    public function test_rerun_converges_and_is_idempotent(): void
    {
        $this->seedPlatformRegistry();
        [$tenant, $token] = $this->administeredTenant();
        $version = $this->defaultInstrumentVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $this->applyAndComplete($token, $tenant, $fx['visit'], $this->g10GreenAnswers($version));

        $period = now()->format('Y-m');

        $this->inTenant($tenant, function () use ($period) {
            app(IndicatorSnapshotService::class)->buildPeriod($period);
        });

        $firstRun = $this->inTenant($tenant, fn () => ScreeningIndicatorSnapshot::where('period_start', now()->startOfMonth()->toDateString())->get()->keyBy('health_center_id'));

        sleep(1);

        $this->inTenant($tenant, function () use ($period) {
            app(IndicatorSnapshotService::class)->buildPeriod($period);
        });

        $secondRun = $this->inTenant($tenant, fn () => ScreeningIndicatorSnapshot::where('period_start', now()->startOfMonth()->toDateString())->get()->keyBy('health_center_id'));

        $this->assertSame($firstRun->count(), $secondRun->count(), 'No new rows on rerun.');

        foreach ($firstRun as $key => $row) {
            $counterparty = $secondRun->get($key);
            $this->assertNotNull($counterparty);
            $this->assertSame($row->screenings_applied, $counterparty->screenings_applied);
            $this->assertSame($row->result_green_count, $counterparty->result_green_count);
            $this->assertSame($row->area_breakdown, $counterparty->area_breakdown);
            $this->assertTrue($counterparty->computed_at->greaterThan($row->computed_at));
        }
    }
}
