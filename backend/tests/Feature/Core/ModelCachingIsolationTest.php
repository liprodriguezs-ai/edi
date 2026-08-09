<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Collections\Models\ChargeStatus;
use Modules\Housing\Models\UnitStatus;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Verifies the three guarantees of the scoped model-caching implementation:
 *
 *  1. READ ISOLATION: UnitStatus::all() under tenant B never returns tenant A's rows,
 *     even if tenant A wrote to the cache first.
 *
 *  2. INVALIDATION SCOPING: a write under tenant A only invalidates tenant A's cache
 *     entry; tenant B's cache entry survives untouched. This is the core correctness
 *     test for the per-tenant $cachePrefix on TenantCatalogModel.
 *
 *  3. CENTRAL CATALOG SHARED: ChargeStatus (no tenant_id) is cached with a single
 *     global entry — no per-tenant differentiation needed or desired.
 *
 * These tests MUST run against real Redis because genealabs/laravel-model-caching
 * requires a TaggableStore (file/array/database are NOT taggable and make the
 * trait a no-op, yielding false greens).
 *
 * Redis availability: 127.0.0.1:6379 — confirmed available in this environment.
 * If Redis is unreachable the test self-skips with a clear message.
 */
class ModelCachingIsolationTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /**
     * Disable per-test transaction wrapping so tenant rows committed by makeTenant()
     * are visible across query boundaries, matching production behaviour.
     *
     * @var array<int, string>
     */
    protected $connectionsToTransact = [];

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireRedis();
        $this->enableModelCaching();

        // Two tenants without firing TenantCreated (no auto-seeder, we control the data)
        $this->tenantA = $this->makeTenant('Cache-A');
        $this->tenantB = $this->makeTenant('Cache-B');

        // Seed just the central catalog (ChargeStatus etc.) once
        $this->seedCentralCatalogs();

        // Seed one UnitStatus row per tenant via raw insert so we bypass
        // the model observer (we just need rows in the table)
        DB::table('unit_status')->insert([
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenantA->id,
                'name' => 'Activo-A',
                'color' => '#00FF00',
                'order' => 1,
                'is_active' => true,
                'is_billable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('unit_status')->insert([
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenantB->id,
                'name' => 'Activo-B',
                'color' => '#FF0000',
                'order' => 1,
                'is_active' => true,
                'is_billable' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Cache::store('redis')->flush();
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        Cache::store('redis')->flush();

        // Clean up tenant rows created by this test
        DB::table('unit_status')
            ->where('tenant_id', $this->tenantA->id)
            ->orWhere('tenant_id', $this->tenantB->id)
            ->delete();

        DB::table('tenant')->whereIn('id', [$this->tenantA->id, $this->tenantB->id])->delete();

        parent::tearDown();
    }

    /**
     * ASSERTION 1: Read isolation.
     *
     * When tenant A warms the cache, a subsequent read under tenant B must
     * return only tenant B's rows — never tenant A's.
     */
    public function test_read_isolation_tenant_b_never_sees_tenant_a_rows(): void
    {
        // Warm cache under tenant A
        tenancy()->initialize($this->tenantA);
        $rowsA = UnitStatus::all();
        tenancy()->end();

        $this->assertCount(1, $rowsA, 'Tenant A should have exactly one UnitStatus row');
        $this->assertEquals('Activo-A', $rowsA->first()->name);

        // Read under tenant B — cache for tenant A is warm, must NOT bleed
        tenancy()->initialize($this->tenantB);
        $rowsB = UnitStatus::all();
        tenancy()->end();

        $this->assertCount(1, $rowsB, 'Tenant B should have exactly one UnitStatus row');
        $this->assertEquals('Activo-B', $rowsB->first()->name, 'Tenant B must NOT receive tenant A\'s cached rows');

        // Explicit leak check
        $leaked = $rowsB->filter(fn ($r): bool => str_contains($r->name, '-A'));
        $this->assertTrue(
            $leaked->isEmpty(),
            "Tenant B received tenant A rows: {$leaked->pluck('name')->join(', ')}"
        );
    }

    /**
     * ASSERTION 2: Invalidation scoping.
     *
     * Warm cache for BOTH tenants, then perform a write under tenant A.
     * Tenant B's cache entry must survive — proving the per-tenant $cachePrefix
     * scoped the invalidation tag.
     *
     * Without the $cachePrefix on TenantCatalogModel, genealabs uses the model
     * class name as the sole invalidation tag (shared across tenants), and a
     * write by tenant A would evict tenant B's cache too. This test catches
     * exactly that regression.
     */
    public function test_invalidation_is_scoped_to_the_writing_tenant(): void
    {
        // Warm cache for both tenants
        tenancy()->initialize($this->tenantA);
        UnitStatus::all(); // populates cache for A
        tenancy()->end();

        tenancy()->initialize($this->tenantB);
        UnitStatus::all(); // populates cache for B
        tenancy()->end();

        // Verify that both tenant caches are warm by confirming Redis has entries
        $redis = Cache::store('redis')->getStore()->getRedis()->connection('cache');
        $keysBefore = collect($redis->keys('*'))->filter(
            fn (string $k): bool => str_contains(strtolower($k), 'unit')
        );
        $this->assertGreaterThanOrEqual(2, $keysBefore->count(), 'Both tenant caches should be warm before the write');

        // Write/update under tenant A — this should flush only A's cache
        tenancy()->initialize($this->tenantA);
        DB::table('unit_status')
            ->where('tenant_id', $this->tenantA->id)
            ->update(['color' => '#111111', 'updated_at' => now()]);

        // Trigger cache invalidation the way the model observer would (same as a Model::save())
        (new UnitStatus)->flushCache();
        tenancy()->end();

        // Tenant B's cache must still be alive
        tenancy()->initialize($this->tenantB);

        // Re-query; if the cache survives the result is served from cache
        // (we can't introspect "was it a cache hit?" directly, so we verify
        // the query result matches B's row — demonstrating scope integrity)
        $rowsB = UnitStatus::all();
        tenancy()->end();

        $this->assertCount(1, $rowsB, 'Tenant B should still have one UnitStatus row after tenant A write');
        $this->assertEquals('Activo-B', $rowsB->first()->name);

        // Key count: tenant B's unit_status keys must still be present in Redis
        $keysAfter = collect($redis->keys('*'))->filter(
            fn (string $k): bool => str_contains(strtolower($k), 'unit') && str_contains($k, $this->tenantB->id)
        );
        $this->assertGreaterThan(
            0,
            $keysAfter->count(),
            "Tenant B's cache was incorrectly evicted by a write in tenant A (no keys with tenant B's ID remain for unit_status)"
        );
    }

    /**
     * ASSERTION 3: Central catalog is cached with a single global entry.
     *
     * ChargeStatus has no BelongsToTenant and uses CentralCatalogModel.
     * Both tenants should read the same rows (it is genuinely shared data).
     * The cache must not produce a per-tenant entry.
     */
    public function test_central_catalog_is_cached_globally_once(): void
    {
        $redis = Cache::store('redis')->getStore()->getRedis()->connection('cache');

        Cache::store('redis')->flush();

        // Read ChargeStatus under tenant A — warms the cache
        tenancy()->initialize($this->tenantA);
        $statusesA = ChargeStatus::all();
        tenancy()->end();

        $keysAfterA = collect($redis->keys('*'))->filter(
            fn (string $k): bool => str_contains(strtolower($k), 'charge') && str_contains(strtolower($k), 'status')
        );
        $countAfterA = $keysAfterA->count();
        $this->assertGreaterThan(0, $countAfterA, 'ChargeStatus should have written at least one cache key');

        // Read ChargeStatus under tenant B — must be served from cache (same key)
        tenancy()->initialize($this->tenantB);
        $statusesB = ChargeStatus::all();
        tenancy()->end();

        $keysAfterB = collect($redis->keys('*'))->filter(
            fn (string $k): bool => str_contains(strtolower($k), 'charge') && str_contains(strtolower($k), 'status')
        );

        // The number of keys must not increase: B reuses A's cache entry
        $this->assertEquals(
            $countAfterA,
            $keysAfterB->count(),
            'A second tenant reading ChargeStatus must not create a second set of cache keys (it should reuse the shared entry)'
        );

        // Both tenants receive the same row set
        $this->assertEquals(
            $statusesA->pluck('id')->sort()->values()->toArray(),
            $statusesB->pluck('id')->sort()->values()->toArray(),
            'Both tenants must receive identical ChargeStatus rows (it is central/shared data)'
        );
    }

    /**
     * ASSERTION 4: No prefix-less (cross-tenant) cache key when tenancy absent.
     *
     * When tenancy is NOT initialized, TenantCatalogModel sets $isCachable=false
     * in its constructor so that NO cache entry is written at all. This prevents
     * a global (prefix-less) key from appearing in Redis, which would let a
     * subsequent tenant-scoped read incorrectly serve unscoped data.
     *
     * Mechanism: $isCachable=false → Caching::isCachable() returns false →
     * ModelCaching::all() falls through to parent::all() without touching Redis.
     */
    public function test_unscoped_query_without_tenancy_does_not_write_cache(): void
    {
        // Ensure tenancy is ended (no initialized context)
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $redis = Cache::store('redis')->getStore()->getRedis()->connection('cache');

        Cache::store('redis')->flush();
        $keysBefore = count($redis->keys('*'));

        // Perform an unscoped all() without tenancy initialized.
        // This goes through parent::all() directly (BelongsToTenant scope
        // and TenantScope are not applied, so we get all rows — but more
        // importantly, caching must be completely disabled).
        $results = UnitStatus::all();

        $keysAfter = collect($redis->keys('*'));

        // No cache key should have been written — especially no prefix-less key.
        $unitKeys = $keysAfter->filter(
            fn (string $k): bool => str_contains(strtolower($k), 'unit') && str_contains(strtolower($k), 'status')
        );

        $this->assertTrue(
            $unitKeys->isEmpty(),
            'UnitStatus::all() without tenancy must NOT write any cache key (isCachable=false prevents it). '.
            'Found keys: '.$unitKeys->join(', ')
        );

        $this->assertEquals(
            $keysBefore,
            $keysAfter->count(),
            'Total Redis key count must not change when caching is disabled due to absent tenancy'
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function requireRedis(): void
    {
        try {
            Cache::store('redis')->getStore()->getRedis()->connection('cache')->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped("Redis is not available ({$e->getMessage()}). These tests require a real TaggableStore.");
        }
    }

    private function enableModelCaching(): void
    {
        config([
            'laravel-model-caching.enabled' => true,
            'laravel-model-caching.store' => 'redis',
        ]);
    }

    private function seedCentralCatalogs(): void
    {
        // Only insert the ChargeStatus::PENDING row if it doesn't already exist,
        // so the test is idempotent across RefreshDatabase cycles.
        if (! DB::table('charge_status')->where('id', ChargeStatus::PENDING)->exists()) {
            DB::table('charge_status')->insert([
                'id' => ChargeStatus::PENDING,
                'name' => 'Pendiente',
                'color' => '#CCC',
                'order' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
