<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Services\CollectionsReportService;
use Modules\Tenant\Models\Tenant;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Feature tests for CollectionsReportController.
 *
 * Covers: GET /collections/aging  (read.report_collections)
 *
 * Tests verify aging bucket placement, top_debtors cap, collection_rate_pct math,
 * by_status grouping, tenant isolation, and 401/403 guards.
 */
final class CollectionsReportControllerTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** @param  array<int, string>  $abilities */
    private function makeToken(Tenant $tenant, array $abilities): string
    {
        return $this->makeUser($tenant)->createToken('aging-test', $abilities)->plainTextToken;
    }

    /**
     * Create a minimal unit inside the tenant's scope and return its id.
     */
    private function makeUnit(Tenant $tenant): string
    {
        return (string) $tenant->run(static function (): string {
            $unitTypeId = DB::table('unit_type')->value('id');
            $unitStatusId = DB::table('unit_status')->value('id');

            return (string) DB::table('unit')->insertGetId([
                'id' => (string) Str::uuid(),
                'number' => Str::random(4),
                'unit_type_id' => $unitTypeId,
                'unit_status_id' => $unitStatusId,
                'tenant_id' => tenancy()->tenant->id,
                'user_created_id' => null,
                'user_updated_id' => null,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ], 'id');
        });
    }

    /**
     * Create a minimal charge_concept if none exists and return its id.
     */
    private function ensureChargeConcept(Tenant $tenant): string
    {
        return (string) $tenant->run(static function (): string {
            $existing = DB::table('charge_concept')->value('id');
            if ($existing) {
                return $existing;
            }

            // charge_concept now holds only identity (code/name/category); the
            // charge behavior lives on charge_policy. The aging report only needs the
            // concept FK on the charge, so a minimal identity concept suffices here.
            $categoryId = DB::table('charge_concept_category')->value('id');

            $id = (string) Str::uuid();
            DB::table('charge_concept')->insert([
                'id' => $id,
                'code' => 'MANT_PRUEBA',
                'name' => 'Concepto Prueba',
                'charge_concept_category_id' => $categoryId,
                'is_active' => true,
                'tenant_id' => tenancy()->tenant->id,
                'user_created_id' => null,
                'user_updated_id' => null,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);

            return $id;
        });
    }

    /**
     * Create a charge directly in the database with precise control over all
     * relevant fields. Returns the charge id.
     *
     * @param  string  $chargeStatusId  One of ChargeStatus::* constants.
     */
    private function makeCharge(
        Tenant $tenant,
        string $unitId,
        string $chargeStatusId,
        int $expectedAmount,
        int $paidAmount,
        int $discountAmount,
        string $dueDate,
        bool $isCancelled = false,
    ): string {
        $id = (string) Str::uuid();
        $chargeConceptId = $this->ensureChargeConcept($tenant);

        $tenant->run(static function () use ($id, $unitId, $chargeStatusId, $expectedAmount, $paidAmount, $discountAmount, $dueDate, $isCancelled, $chargeConceptId): void {
            DB::table('charge')->insert([
                'id' => $id,
                'unit_id' => $unitId,
                'charge_concept_id' => $chargeConceptId,
                'charge_status_id' => $chargeStatusId,
                'expected_amount' => $expectedAmount,
                'paid_amount' => $paidAmount,
                'discount_amount' => $discountAmount,
                'due_date' => $dueDate,
                'issued_at' => $dueDate,
                'is_cancelled' => $isCancelled,
                'grace_days' => 0,
                'tenant_id' => tenancy()->tenant->id,
                'user_created_id' => null,
                'user_updated_id' => null,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);
        });

        return $id;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function test_aging_returns_correct_structure(): void
    {
        $tenantA = $this->makeTenant('Cluster Aging');
        $this->seedTenant($tenantA);
        $token = $this->makeToken($tenantA, ['read.report_collections']);

        $response = $this->tenantRequest($token, $tenantA)
            ->getJson('/api/collections/aging');

        $response->assertOk();

        // Full contract check — every key the frontend ICobranzaPayload schema needs.
        $response->assertJsonStructure([
            'totals' => [
                'total_outstanding' => ['amount', 'currency', 'formatted'],
                'units_with_debt',
                'avg_days_overdue',
                'collection_rate_pct',
            ],
            'buckets' => [
                'current' => ['amount', 'currency', 'formatted'],
                'd1_30' => ['amount', 'currency', 'formatted'],
                'd31_60' => ['amount', 'currency', 'formatted'],
                'd61_90' => ['amount', 'currency', 'formatted'],
                'd90_plus' => ['amount', 'currency', 'formatted'],
            ],
            'by_status',
            'top_debtors',
        ]);

        $data = $response->json();

        $totals = $data['totals'];
        $this->assertIsString($totals['total_outstanding']['amount']);
        $this->assertSame('MXN', $totals['total_outstanding']['currency']);
        $this->assertIsInt($totals['collection_rate_pct']);
        $this->assertIsInt($totals['avg_days_overdue']);
        $this->assertIsInt($totals['units_with_debt']);

        // Buckets key (was 'aging').
        $this->assertArrayHasKey('buckets', $data);
        $this->assertArrayNotHasKey('aging', $data);
        $this->assertArrayNotHasKey('total_outstanding_minor', $data); // moved to totals
    }

    public function test_charge_45_days_overdue_lands_in_d31_60_bucket(): void
    {
        $tenantA = $this->makeTenant('Cluster Bucket');
        $this->seedTenant($tenantA);
        $token = $this->makeToken($tenantA, ['read.report_collections']);

        // Set now to a fixed date so we control the "45 days ago" math.
        Carbon::setTestNow(Carbon::create(2026, 6, 16, 12, 0, 0, 'UTC'));
        $dueDate = Carbon::now()->subDays(45)->toDateString(); // 45 days past due

        $unitId = $this->makeUnit($tenantA);
        // outstanding = 100000 - 0 - 25000 = 75000
        $this->makeCharge($tenantA, $unitId, ChargeStatus::OVERDUE, 100000, 25000, 0, $dueDate);

        $response = $this->tenantRequest($token, $tenantA)
            ->getJson('/api/collections/aging');

        $response->assertOk();
        $buckets = $response->json('buckets');

        $this->assertSame('75000', $buckets['d31_60']['amount']);
        $this->assertSame('0', $buckets['d1_30']['amount']);
        $this->assertSame('0', $buckets['d61_90']['amount']);
        $this->assertSame('0', $buckets['d90_plus']['amount']);
    }

    public function test_top_debtors_capped_at_10_and_ordered_desc(): void
    {
        $tenantA = $this->makeTenant('Cluster Debtors');
        $this->seedTenant($tenantA);
        $token = $this->makeToken($tenantA, ['read.report_collections']);

        Carbon::setTestNow(Carbon::create(2026, 6, 16, 12, 0, 0, 'UTC'));
        $dueDate = Carbon::now()->subDays(60)->toDateString();

        // Create 15 units, each with an OVERDUE charge. Last unit has the largest debt.
        for ($i = 1; $i <= 15; $i++) {
            $unitId = $this->makeUnit($tenantA);
            $this->makeCharge($tenantA, $unitId, ChargeStatus::OVERDUE, $i * 10000, 0, 0, $dueDate);
        }

        $response = $this->tenantRequest($token, $tenantA)
            ->getJson('/api/collections/aging');

        $response->assertOk();
        $topDebtors = $response->json('top_debtors');

        $this->assertCount(10, $topDebtors, 'top_debtors must be capped at 10');

        // First debtor must have the highest outstanding.
        $first = (int) $topDebtors[0]['outstanding']['amount'];
        $last = (int) $topDebtors[9]['outstanding']['amount'];
        $this->assertGreaterThan($last, $first, 'top_debtors must be sorted desc by outstanding');

        // Each debtor row must have charge_count (frontend ITopDebtor schema).
        $this->assertArrayHasKey('charge_count', $topDebtors[0]);
        $this->assertIsInt($topDebtors[0]['charge_count']);
        $this->assertGreaterThanOrEqual(1, $topDebtors[0]['charge_count']);
    }

    public function test_collection_rate_pct_is_integer_between_0_and_100(): void
    {
        $tenantA = $this->makeTenant('Cluster Rate');
        $this->seedTenant($tenantA);
        $token = $this->makeToken($tenantA, ['read.report_collections']);

        Carbon::setTestNow(Carbon::create(2026, 6, 16, 12, 0, 0, 'UTC'));
        $dueDateThisMonth = '2026-06-20'; // due this month

        // Seed 5 charges due this month: 2 COMPLETED, 1 OVERDUE, 1 PARTIAL, 1 PENDING.
        $unitId = $this->makeUnit($tenantA);
        $this->makeCharge($tenantA, $unitId, ChargeStatus::COMPLETED, 50000, 50000, 0, $dueDateThisMonth);
        $this->makeCharge($tenantA, $unitId, ChargeStatus::COMPLETED, 50000, 50000, 0, $dueDateThisMonth);
        $this->makeCharge($tenantA, $unitId, ChargeStatus::OVERDUE, 50000, 0, 0, $dueDateThisMonth);
        $this->makeCharge($tenantA, $unitId, ChargeStatus::PARTIAL, 50000, 10000, 0, $dueDateThisMonth);
        $this->makeCharge($tenantA, $unitId, ChargeStatus::PENDING, 50000, 0, 0, $dueDateThisMonth);

        $response = $this->tenantRequest($token, $tenantA)
            ->getJson('/api/collections/aging');

        $response->assertOk();
        $pct = $response->json('totals.collection_rate_pct');

        $this->assertIsInt($pct);
        $this->assertGreaterThanOrEqual(0, $pct);
        $this->assertLessThanOrEqual(100, $pct);
        // 2 completed out of 5 = 40%
        $this->assertSame(40, $pct);
    }

    public function test_by_status_contains_overdue_key_and_spanish_label(): void
    {
        $tenantA = $this->makeTenant('Cluster Status');
        $this->seedTenant($tenantA);
        $token = $this->makeToken($tenantA, ['read.report_collections']);

        Carbon::setTestNow(Carbon::create(2026, 6, 16, 12, 0, 0, 'UTC'));
        $dueDate = Carbon::now()->subDays(10)->toDateString();

        $unitId = $this->makeUnit($tenantA);
        $this->makeCharge($tenantA, $unitId, ChargeStatus::OVERDUE, 75000, 0, 0, $dueDate);

        $response = $this->tenantRequest($token, $tenantA)
            ->getJson('/api/collections/aging');

        $response->assertOk();
        $byStatus = $response->json('by_status');

        $overdueItem = collect($byStatus)->firstWhere('status_key', 'overdue');
        $this->assertNotNull($overdueItem, 'by_status must include overdue key');
        $this->assertSame('overdue', $overdueItem['status_key']);
        // Label comes from the seeded ChargeStatus name (Spanish).
        $this->assertNotEmpty($overdueItem['label']);
        $this->assertIsString($overdueItem['amount']['amount']);
        $this->assertSame('MXN', $overdueItem['amount']['currency']);
    }

    public function test_cancelled_charges_excluded_from_aging(): void
    {
        $tenantA = $this->makeTenant('Cluster Cancelled');
        $this->seedTenant($tenantA);
        $token = $this->makeToken($tenantA, ['read.report_collections']);

        Carbon::setTestNow(Carbon::create(2026, 6, 16, 12, 0, 0, 'UTC'));
        $dueDate = Carbon::now()->subDays(20)->toDateString();

        $unitId = $this->makeUnit($tenantA);
        $this->makeCharge($tenantA, $unitId, ChargeStatus::OVERDUE, 50000, 0, 0, $dueDate, isCancelled: true);

        $response = $this->tenantRequest($token, $tenantA)
            ->getJson('/api/collections/aging');

        $response->assertOk();
        $this->assertSame('0', $response->json('totals.total_outstanding.amount'));
    }

    public function test_aging_tenant_isolation(): void
    {
        $tenantA = $this->makeTenant('Cluster Iso AgA');
        $tenantB = $this->makeTenant('Cluster Iso AgB');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);
        $token = $this->makeToken($tenantA, ['read.report_collections']);

        Carbon::setTestNow(Carbon::create(2026, 6, 16, 12, 0, 0, 'UTC'));
        $dueDate = Carbon::now()->subDays(15)->toDateString();

        // Tenant B: 999000 outstanding. Tenant A: 100000 outstanding.
        $unitA = $this->makeUnit($tenantA);
        $unitB = $this->makeUnit($tenantB);
        $this->makeCharge($tenantA, $unitA, ChargeStatus::OVERDUE, 100000, 0, 0, $dueDate);
        $this->makeCharge($tenantB, $unitB, ChargeStatus::OVERDUE, 999000, 0, 0, $dueDate);

        $response = $this->tenantRequest($token, $tenantA)
            ->getJson('/api/collections/aging');

        $response->assertOk();
        $this->assertSame('100000', $response->json('totals.total_outstanding.amount'));
    }

    public function test_avg_days_overdue_is_correct_integer(): void
    {
        $tenantA = $this->makeTenant('Cluster AvgDays');
        $this->seedTenant($tenantA);
        $token = $this->makeToken($tenantA, ['read.report_collections']);

        Carbon::setTestNow(Carbon::create(2026, 6, 16, 12, 0, 0, 'UTC'));

        // Two charges: 10 days overdue and 20 days overdue → avg = 15.
        $unitId = $this->makeUnit($tenantA);
        $this->makeCharge($tenantA, $unitId, ChargeStatus::OVERDUE, 50000, 0, 0, '2026-06-06'); // 10 days ago
        $this->makeCharge($tenantA, $unitId, ChargeStatus::OVERDUE, 50000, 0, 0, '2026-05-27'); // 20 days ago

        $response = $this->tenantRequest($token, $tenantA)
            ->getJson('/api/collections/aging');

        $response->assertOk();
        $avgDays = $response->json('totals.avg_days_overdue');

        $this->assertIsInt($avgDays);
        $this->assertSame(15, $avgDays);
    }

    /**
     * A PARTIAL charge that is NOT yet past due must not dilute avg_days_overdue.
     *
     * The aging set loads OVERDUE + PARTIAL charges, but a PARTIAL charge can be
     * due in the future. Only charges actually past due (daysPastDue > 0) may
     * contribute to the average — both numerator AND denominator. Otherwise the
     * not-yet-due charge would pull the average toward 0.
     */
    public function test_avg_days_overdue_not_diluted_by_not_yet_due_partial(): void
    {
        $tenantA = $this->makeTenant('Cluster AvgPartial');
        $this->seedTenant($tenantA);
        $token = $this->makeToken($tenantA, ['read.report_collections']);

        Carbon::setTestNow(Carbon::create(2026, 6, 16, 12, 0, 0, 'UTC'));

        $unitId = $this->makeUnit($tenantA);
        // Two OVERDUE charges actually past due: 10 and 20 days → avg of these = 15.
        $this->makeCharge($tenantA, $unitId, ChargeStatus::OVERDUE, 50000, 0, 0, '2026-06-06'); // 10 days ago
        $this->makeCharge($tenantA, $unitId, ChargeStatus::OVERDUE, 50000, 0, 0, '2026-05-27'); // 20 days ago
        // A PARTIAL charge due in the FUTURE (14 days out) — must be ignored by the average.
        $this->makeCharge($tenantA, $unitId, ChargeStatus::PARTIAL, 50000, 10000, 0, '2026-06-30');

        $response = $this->tenantRequest($token, $tenantA)
            ->getJson('/api/collections/aging');

        $response->assertOk();
        $avgDays = $response->json('totals.avg_days_overdue');

        $this->assertIsInt($avgDays);
        // Average is over the 2 truly-overdue charges only: (10 + 20) / 2 = 15.
        // If the not-yet-due PARTIAL diluted it, the count would be 3 → round(30/3) = 10.
        $this->assertSame(15, $avgDays, 'not-yet-due PARTIAL must not dilute avg_days_overdue');
    }

    public function test_aging_requires_authentication(): void
    {
        $this->getJson('/api/collections/aging')->assertStatus(401);
    }

    public function test_aging_requires_permission(): void
    {
        $tenantA = $this->makeTenant('Cluster AgPerm');
        $this->seedTenant($tenantA);
        $wrongToken = $this->makeToken($tenantA, ['read.payment']);

        $this->tenantRequest($wrongToken, $tenantA)
            ->getJson('/api/collections/aging')
            ->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cache behaviour
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cache::remember is invoked with a tenant-scoped key when caching is enabled.
     *
     * Design note: Stancl's CacheTenancyBootstrapper replaces the array cache
     * store on every tenancy initialize, so cross-request stale-data assertions
     * are unreliable with the array driver in feature tests. We verify the key
     * format via the service's buildCacheKey helper directly.
     */
    public function test_aging_cache_key_contains_tenant_id(): void
    {
        config(['dashboard.reports.cache_enabled' => true, 'dashboard.reports.cache_ttl' => 60]);

        $tenantA = $this->makeTenant('Cluster AgCacheKey');
        $this->seedTenant($tenantA);
        $token = $this->makeToken($tenantA, ['read.report_collections']);

        $response = $this->tenantRequest($token, $tenantA)
            ->getJson('/api/collections/aging');
        $response->assertOk();

        $tenantId = $tenantA->id;

        $tenantA->run(function () use ($tenantId): void {
            $service = app(CollectionsReportService::class);

            $method = new \ReflectionMethod($service, 'buildCacheKey');
            $method->setAccessible(true);
            $key = $method->invoke($service, $tenantId, 'aging', ['timezone' => 'America/Mexico_City']);

            $this->assertStringStartsWith('dashboard_report:', $key);
            $this->assertStringContainsString($tenantId, $key);
            $this->assertStringContainsString('aging', $key);
        });
    }

    /**
     * Tenant A and B cache entries are independent (no cross-tenant key collision).
     */
    public function test_aging_cache_is_tenant_scoped(): void
    {
        config(['dashboard.reports.cache_enabled' => true, 'dashboard.reports.cache_ttl' => 60]);
        Cache::flush();

        $tenantA = $this->makeTenant('Cluster AgScopeA');
        $tenantB = $this->makeTenant('Cluster AgScopeB');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        Carbon::setTestNow(Carbon::create(2026, 6, 16, 12, 0, 0, 'UTC'));
        $dueDate = Carbon::now()->subDays(10)->toDateString();

        $unitA = $this->makeUnit($tenantA);
        $unitB = $this->makeUnit($tenantB);
        $this->makeCharge($tenantA, $unitA, ChargeStatus::OVERDUE, 111111, 0, 0, $dueDate);
        $this->makeCharge($tenantB, $unitB, ChargeStatus::OVERDUE, 999999, 0, 0, $dueDate);

        $tokenA = $this->makeToken($tenantA, ['read.report_collections']);
        $tokenB = $this->makeToken($tenantB, ['read.report_collections']);

        $respA = $this->tenantRequest($tokenA, $tenantA)->getJson('/api/collections/aging');
        $respB = $this->tenantRequest($tokenB, $tenantB)->getJson('/api/collections/aging');

        $respA->assertOk();
        $respB->assertOk();

        $this->assertSame('111111', $respA->json('totals.total_outstanding.amount'));
        $this->assertSame('999999', $respB->json('totals.total_outstanding.amount'));
    }

    /**
     * Cache disabled — every request returns fresh data.
     */
    public function test_aging_cache_disabled_returns_fresh_data(): void
    {
        config(['dashboard.reports.cache_enabled' => false]);
        Cache::flush();

        $tenantA = $this->makeTenant('Cluster AgCacheOff');
        $this->seedTenant($tenantA);
        $token = $this->makeToken($tenantA, ['read.report_collections']);

        Carbon::setTestNow(Carbon::create(2026, 6, 16, 12, 0, 0, 'UTC'));
        $dueDate = Carbon::now()->subDays(5)->toDateString();
        $unitId = $this->makeUnit($tenantA);
        $this->makeCharge($tenantA, $unitId, ChargeStatus::OVERDUE, 50000, 0, 0, $dueDate);

        $first = $this->tenantRequest($token, $tenantA)->getJson('/api/collections/aging');
        $first->assertOk();
        $this->assertSame('50000', $first->json('totals.total_outstanding.amount'));

        // Add more debt — with cache off, it should appear immediately.
        $unitId2 = $this->makeUnit($tenantA);
        $this->makeCharge($tenantA, $unitId2, ChargeStatus::OVERDUE, 30000, 0, 0, $dueDate);

        $second = $this->tenantRequest($token, $tenantA)->getJson('/api/collections/aging');
        $second->assertOk();
        $this->assertSame(
            '80000',
            $second->json('totals.total_outstanding.amount'),
            'Cache disabled: second request must return fresh data',
        );
    }
}
