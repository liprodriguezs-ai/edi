<?php

declare(strict_types=1);

namespace Modules\Collections\Tests\Feature\Services;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Collections\Exceptions\DelinquencyRestrictedException;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Services\ChargeStatusService;
use Modules\Collections\Services\DelinquencyRestrictionService;
use Modules\Collections\Support\DelinquencySettings;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Rule-matrix coverage for DelinquencyRestrictionService (design §3, R-DELQ-1..4).
 *
 * Covers: master switch off, per-feature toggle off, below/at/above threshold,
 * min_overdue_amount=0, PARTIAL-within-term is NEVER blocked (the trap this
 * design avoids — do not copy the [OVERDUE, PARTIAL] filter from other call
 * sites), the audience-split exception payload, and stateForUnits() per-unit
 * precision for a resident holding one good-standing and one delinquent unit.
 */
final class DelinquencyRestrictionServiceTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected $connectionsToTransact = [];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('Delinquency Tenant');
        $this->seedTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeUnit(): Unit
    {
        $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
        $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));

        return Unit::create([
            'unit_type_id' => $unitType->id,
            'number' => (string) mt_rand(1, 99999),
            'unit_status_id' => $unitStatus->id,
            'ownership_share' => 1.0,
        ]);
    }

    /** Force a unit's aggregate charge_status_id — bypasses the recompute service on purpose. */
    private function setUnitStatus(Unit $unit, string $chargeStatusId): Unit
    {
        $unit->charge_status_id = $chargeStatusId;
        $unit->save();

        return $unit->fresh();
    }

    private function makeChargeConcept(): ChargeConcept
    {
        $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));

        return ChargeConcept::create([
            'code' => 'DELQ_'.strtoupper(substr(md5(uniqid('', true)), 0, 6)),
            'name' => 'Delinquency test concept',
            'charge_concept_category_id' => $category->id,
            'is_active' => true,
        ]);
    }

    /** One OVERDUE, non-cancelled charge for $unit with the given owed remainder. */
    private function makeOverdueCharge(Unit $unit, int $expected, int $paid = 0): Charge
    {
        $concept = $this->makeChargeConcept();

        return Charge::create([
            'charge_concept_id' => $concept->id,
            'unit_id' => $unit->id,
            'expected_amount' => $expected,
            'paid_amount' => $paid,
            'issued_at' => '2026-01-01',
            'due_date' => '2026-01-10',
            'charge_status_id' => ChargeStatus::OVERDUE,
            'is_cancelled' => false,
        ]);
    }

    /** One PARTIAL, non-cancelled charge (paid something, still within term). */
    private function makePartialCharge(Unit $unit, int $expected, int $paid): Charge
    {
        $concept = $this->makeChargeConcept();

        return Charge::create([
            'charge_concept_id' => $concept->id,
            'unit_id' => $unit->id,
            'expected_amount' => $expected,
            'paid_amount' => $paid,
            'issued_at' => '2026-06-01',
            'due_date' => '2026-07-10',
            'charge_status_id' => ChargeStatus::PARTIAL,
            'is_cancelled' => false,
        ]);
    }

    /**
     * Always builds a NEW instance, bypassing the container's scoped/singleton
     * cache — mirrors "next request" in production, where a fresh instance
     * re-reads settings. Resolving via app() here would return the SAME cached
     * instance across a test method, hiding the settings() memoization by
     * accident instead of exercising it.
     */
    private function service(): DelinquencyRestrictionService
    {
        return new DelinquencyRestrictionService(app(ChargeStatusService::class));
    }

    private function updateSetting(string $slug, mixed $value, string $type): void
    {
        Setting::updateOrCreate(
            ['slug' => $slug],
            ['name' => $slug, 'slug' => $slug, 'value' => $value, 'type' => $type]
        );
    }

    private function enableRestrictions(int $minOverdueAmount = 0, bool $blockAmenities = true, bool $blockVisitorAccess = true, bool $blockPackageIntake = true): void
    {
        $this->tenant->run(function () use ($minOverdueAmount, $blockAmenities, $blockVisitorAccess, $blockPackageIntake): void {
            $this->updateSetting(DelinquencySettings::RESTRICTIONS_ENABLED, true, Setting::TYPE_BOOLEAN);
            $this->updateSetting(DelinquencySettings::MIN_OVERDUE_AMOUNT, $minOverdueAmount, Setting::TYPE_INTEGER);
            $this->updateSetting(DelinquencySettings::BLOCK_AMENITIES, $blockAmenities, Setting::TYPE_BOOLEAN);
            $this->updateSetting(DelinquencySettings::BLOCK_VISITOR_ACCESS, $blockVisitorAccess, Setting::TYPE_BOOLEAN);
            $this->updateSetting(DelinquencySettings::BLOCK_PACKAGE_INTAKE, $blockPackageIntake, Setting::TYPE_BOOLEAN);
        });
    }

    // =========================================================================
    // Master switch
    // =========================================================================

    public function test_master_switch_off_never_blocks(): void
    {
        $this->tenant->run(function (): void {
            $unit = $this->makeUnit();
            $this->setUnitStatus($unit, ChargeStatus::OVERDUE);
            $this->makeOverdueCharge($unit, 1_000_000);

            // restrictions_enabled defaults to false (seeded) — no explicit enable call.
            $this->assertFalse($this->service()->isRestricted($unit->id, DelinquencyRestrictionService::FEATURE_AMENITIES));
        });
    }

    // =========================================================================
    // Per-feature toggle
    // =========================================================================

    public function test_feature_toggle_off_exempts_that_feature_only(): void
    {
        $this->tenant->run(function (): void {
            $unit = $this->makeUnit();
            $this->setUnitStatus($unit, ChargeStatus::OVERDUE);
            $this->makeOverdueCharge($unit, 1_000_000);
        });

        $this->enableRestrictions(minOverdueAmount: 0, blockAmenities: false, blockVisitorAccess: true, blockPackageIntake: true);

        $this->tenant->run(function (): void {
            $unit = Unit::first();

            $this->assertFalse($this->service()->isRestricted($unit->id, DelinquencyRestrictionService::FEATURE_AMENITIES));
            $this->assertTrue($this->service()->isRestricted($unit->id, DelinquencyRestrictionService::FEATURE_VISITOR_ACCESS));
        });
    }

    // =========================================================================
    // Threshold
    // =========================================================================

    public function test_below_threshold_is_not_blocked(): void
    {
        $this->tenant->run(function (): void {
            $unit = $this->makeUnit();
            $this->setUnitStatus($unit, ChargeStatus::OVERDUE);
            $this->makeOverdueCharge($unit, 499_999);
        });

        $this->enableRestrictions(minOverdueAmount: 500_000);

        $this->tenant->run(function (): void {
            $unit = Unit::first();
            $this->assertFalse($this->service()->isRestricted($unit->id, DelinquencyRestrictionService::FEATURE_AMENITIES));
        });
    }

    public function test_at_threshold_is_blocked(): void
    {
        $this->tenant->run(function (): void {
            $unit = $this->makeUnit();
            $this->setUnitStatus($unit, ChargeStatus::OVERDUE);
            $this->makeOverdueCharge($unit, 500_000);
        });

        $this->enableRestrictions(minOverdueAmount: 500_000);

        $this->tenant->run(function (): void {
            $unit = Unit::first();
            $this->assertTrue($this->service()->isRestricted($unit->id, DelinquencyRestrictionService::FEATURE_AMENITIES));
        });
    }

    public function test_above_threshold_is_blocked(): void
    {
        $this->tenant->run(function (): void {
            $unit = $this->makeUnit();
            $this->setUnitStatus($unit, ChargeStatus::OVERDUE);
            $this->makeOverdueCharge($unit, 500_001);
        });

        $this->enableRestrictions(minOverdueAmount: 500_000);

        $this->tenant->run(function (): void {
            $unit = Unit::first();
            $this->assertTrue($this->service()->isRestricted($unit->id, DelinquencyRestrictionService::FEATURE_AMENITIES));
        });
    }

    public function test_zero_threshold_blocks_any_overdue_unit(): void
    {
        $this->tenant->run(function (): void {
            $unit = $this->makeUnit();
            $this->setUnitStatus($unit, ChargeStatus::OVERDUE);
            $this->makeOverdueCharge($unit, 1);
        });

        $this->enableRestrictions(minOverdueAmount: 0);

        $this->tenant->run(function (): void {
            $unit = Unit::first();
            $this->assertTrue($this->service()->isRestricted($unit->id, DelinquencyRestrictionService::FEATURE_AMENITIES));
        });
    }

    // =========================================================================
    // PARTIAL is never delinquent — the trap this design avoids (ADR-3)
    // =========================================================================

    public function test_partial_within_term_is_never_blocked_even_with_large_unpaid_remainder(): void
    {
        $this->tenant->run(function (): void {
            $unit = $this->makeUnit();
            $this->setUnitStatus($unit, ChargeStatus::PARTIAL);
            // Large unpaid remainder on a PARTIAL charge — still must not block.
            $this->makePartialCharge($unit, 10_000_000, 1_000_000);
        });

        $this->enableRestrictions(minOverdueAmount: 0);

        $this->tenant->run(function (): void {
            $unit = Unit::first();
            $this->assertFalse($this->service()->isRestricted($unit->id, DelinquencyRestrictionService::FEATURE_AMENITIES));
        });
    }

    // =========================================================================
    // Audience-split exception payload (R-DELQ-6)
    // =========================================================================

    public function test_assert_not_restricted_throws_resident_payload_with_amount_and_notice(): void
    {
        $this->tenant->run(function (): void {
            $unit = $this->makeUnit();
            $this->setUnitStatus($unit, ChargeStatus::OVERDUE);
            $this->makeOverdueCharge($unit, 750_000);
        });

        $this->enableRestrictions(minOverdueAmount: 500_000);

        $this->tenant->run(function (): void {
            $unit = Unit::first();

            try {
                $this->service()->assertNotRestricted($unit->id, DelinquencyRestrictionService::FEATURE_AMENITIES);
                $this->fail('Expected DelinquencyRestrictedException to be thrown.');
            } catch (DelinquencyRestrictedException $exception) {
                $response = $exception->render();
                $payload = $response->getData(true);

                $this->assertSame(403, $response->getStatusCode());
                $this->assertSame('DELINQUENT_RESTRICTED', $payload['code']);
                $this->assertArrayHasKey('overdue_balance', $payload);
                $this->assertArrayHasKey('notice_text', $payload);
                $this->assertNotEmpty($payload['notice_text']);
            }
        });
    }

    public function test_assert_not_restricted_guard_payload_has_no_amount_or_debt_wording(): void
    {
        $this->tenant->run(function (): void {
            $unit = $this->makeUnit();
            $this->setUnitStatus($unit, ChargeStatus::OVERDUE);
            $this->makeOverdueCharge($unit, 750_000);
        });

        $this->enableRestrictions(minOverdueAmount: 500_000);

        $this->tenant->run(function (): void {
            $unit = Unit::first();

            try {
                $this->service()->assertNotRestricted($unit->id, DelinquencyRestrictionService::FEATURE_PACKAGE_INTAKE);
                $this->fail('Expected DelinquencyRestrictedException to be thrown.');
            } catch (DelinquencyRestrictedException $exception) {
                $response = $exception->render();
                $payload = $response->getData(true);
                $raw = json_encode($payload);

                $this->assertSame(403, $response->getStatusCode());
                $this->assertSame('DELINQUENT_RESTRICTED', $payload['code']);
                $this->assertArrayNotHasKey('overdue_balance', $payload);
                $this->assertArrayNotHasKey('notice_text', $payload);
                $this->assertStringNotContainsString('adeudo', $raw);
                $this->assertStringNotContainsString('moroso', $raw);
                $this->assertStringNotContainsString('deuda', $raw);
                $this->assertSame('Esta unidad no puede recibir paquetes.', $payload['message']);
            }
        });
    }

    /**
     * sdd-verify CRITICAL: this exception is never caught in production code
     * (self-render() only) — without ShouldntReport, Laravel's handler would
     * log every routine "unit has overdue debt" 403 as an application ERROR
     * and alert Nightwatch on completely normal business behavior.
     */
    public function test_exception_implements_shouldnt_report_so_routine_403s_are_never_logged_as_errors(): void
    {
        $exception = DelinquencyRestrictedException::forGuard();

        $this->assertInstanceOf(ShouldntReport::class, $exception);
    }

    // =========================================================================
    // stateForUnits — per-unit precision (R-DELQ-1 client/server consistency)
    // =========================================================================

    public function test_state_for_units_marks_only_the_overdue_unit_restricted(): void
    {
        $overdueUnitId = null;
        $currentUnitId = null;

        $this->tenant->run(function () use (&$overdueUnitId, &$currentUnitId): void {
            $unitCurrent = $this->makeUnit();
            $unitOverdue = $this->makeUnit();

            $this->setUnitStatus($unitOverdue, ChargeStatus::OVERDUE);
            $this->makeOverdueCharge($unitOverdue, 750_000);
            // unitCurrent has no charges/status — stays null/PENDING, never delinquent.

            $overdueUnitId = $unitOverdue->id;
            $currentUnitId = $unitCurrent->id;
        });

        $this->enableRestrictions(minOverdueAmount: 500_000);

        $this->tenant->run(function () use ($overdueUnitId, $currentUnitId): void {
            $state = $this->service()->stateForUnits([$overdueUnitId, $currentUnitId]);
            $byUnitId = collect($state)->keyBy('unit_id');

            $this->assertTrue($byUnitId[$overdueUnitId]['restricted']);
            $this->assertSame(750_000, $byUnitId[$overdueUnitId]['overdue_balance']);
            $this->assertTrue($byUnitId[$overdueUnitId]['features']['amenities']);

            $this->assertFalse($byUnitId[$currentUnitId]['restricted']);
            $this->assertArrayNotHasKey('overdue_balance', $byUnitId[$currentUnitId]);
        });
    }

    // =========================================================================
    // restrictedUnitIds — batch (R-DELQ-7 backing)
    // =========================================================================

    public function test_restricted_unit_ids_returns_only_units_at_or_above_threshold(): void
    {
        $blockedUnitId = null;
        $belowUnitId = null;

        $this->tenant->run(function () use (&$blockedUnitId, &$belowUnitId): void {
            $blockedUnit = $this->makeUnit();
            $this->setUnitStatus($blockedUnit, ChargeStatus::OVERDUE);
            $this->makeOverdueCharge($blockedUnit, 500_000);

            $belowUnit = $this->makeUnit();
            $this->setUnitStatus($belowUnit, ChargeStatus::OVERDUE);
            $this->makeOverdueCharge($belowUnit, 100_000);

            $blockedUnitId = $blockedUnit->id;
            $belowUnitId = $belowUnit->id;
        });

        $this->enableRestrictions(minOverdueAmount: 500_000);

        $this->tenant->run(function () use ($blockedUnitId, $belowUnitId): void {
            $ids = $this->service()->restrictedUnitIds(DelinquencyRestrictionService::FEATURE_AMENITIES);

            $this->assertContains($blockedUnitId, $ids);
            $this->assertNotContains($belowUnitId, $ids);
            $this->assertCount(1, $ids);
        });
    }

    // =========================================================================
    // Tenant-keyed memo (tenancy-reviewer WARNING — console-command scenario)
    // =========================================================================

    /**
     * A console command that resolves ONE DelinquencyRestrictionService
     * instance (e.g. via method injection before a Tenant::all() loop) must
     * NOT rely on scoped()'s per-request flush for correctness — the
     * settings() memo is keyed by tenant, not by instance alone, so tenant B
     * never inherits tenant A's cached notice_text/restrictions_enabled.
     */
    public function test_settings_memo_is_keyed_by_tenant_when_one_instance_spans_multiple_tenants(): void
    {
        $tenantB = $this->makeTenant('Delinquency Tenant B');
        $this->seedTenant($tenantB);

        // Simulates a console command holding ONE resolved instance across a
        // Tenant::all() + $tenant->run() loop.
        $service = $this->service();

        $this->tenant->run(function () use ($service): void {
            $this->updateSetting(DelinquencySettings::NOTICE_TEXT, 'Aviso personalizado del Tenant A', Setting::TYPE_STRING);
            $this->assertSame('Aviso personalizado del Tenant A', $service->noticeText());
        });

        $tenantB->run(function () use ($service): void {
            // Tenant B never customized notice_text — must resolve to ITS OWN
            // seeded default, never the memoized Tenant A value cached above
            // on the SAME service instance.
            $this->assertSame(DelinquencySettings::DEFAULT_NOTICE_TEXT, $service->noticeText());
        });
    }
}
