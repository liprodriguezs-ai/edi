<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    /**
     * Central, globally-unique tables that ACCUMULATE across a run and whose
     * collisions cause the order-dependent failures.
     *
     * The ONLY central table whose unique index collides across tests is `user`
     * (no `tenant_id`, partial unique on `email` / `phone`, unique `code`).
     * Tests create central users with `User::create([...])` and FIXED literals,
     * so two such creates in one accumulating process collide → the reported
     * `user_email_unique` / `user_phone_unique` 23505.
     *
     * `model_has_*` are included because they reference users by a bare id (no
     * FK to cascade through) and would otherwise leave orphaned role/permission
     * assignments behind.
     *
     * Deliberately EXCLUDED:
     *   - `tenant` and all tenant-scoped tables: their uniques include
     *     `tenant_id`, so cross-tenant accumulation never collides; truncating
     *     `tenant` would also cascade-delete catalog rows mid-suite and break the
     *     tenant FK assumptions the seeders rely on.
     *   - Spatie `roles` / `permissions`: tests create these with
     *     `firstOrCreate` (idempotent on `(name, guard_name)`), so they never
     *     collide, AND several tests depend on a role seeded by an earlier test
     *     still existing — truncating them causes `RoleDoesNotExist`.
     *
     * Truncating `user` with CASCADE clears its dependents (tokens, `user_tenant`,
     * `user_profile`, `settings`, sessions, subscriptions).
     *
     * @var array<int, string>
     */
    private const ACCUMULATING_CENTRAL_TABLES = [
        'model_has_roles',
        'model_has_permissions',
        'user',
    ];

    /**
     * Guarantee a clean, untenanted state at the start of every test.
     *
     * A prior test that leaves tenancy initialized (or whose `$tenant->run()`
     * throws mid-closure) would otherwise scope this test's queries to the wrong
     * tenant — the root cause of order-dependent ("flaky") failures. Ending
     * tenancy here makes each test independent of execution order.
     *
     * The cache flush clears throttle/rate-limiter counters (e.g. the auth-login
     * limiter, keyed by IP) so a test that exhausts a limit cannot bleed a 429
     * into an unrelated later test running from the same 127.0.0.1.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->endTenancy();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->endTenancy();
        $this->resetAccumulatingCentralTables();

        parent::tearDown();
    }

    private function endTenancy(): void
    {
        if (function_exists('tenancy') && tenancy()->initialized) {
            tenancy()->end();
        }
    }

    /**
     * Truncate the central, globally-unique tables that accumulate when a test
     * disables transaction wrapping (`$connectionsToTransact = []`).
     *
     * Those tests do NOT roll back, so every committed central row — most
     * importantly `user` (partial unique on email/phone) — survives into the
     * next test. Under `--order-by=random` two tests creating the same fixed
     * email/phone then collide with a 23505 UniqueConstraintViolation, and the
     * failure count varies by seed. Resetting these tables restores per-test
     * isolation deterministically.
     *
     * Tests that keep transaction wrapping roll back on their own, so this is a
     * no-op for them (skipped to avoid issuing DDL inside their open
     * transaction, which would commit it and disturb RefreshDatabase state).
     */
    private function resetAccumulatingCentralTables(): void
    {
        if (! $this->usesTransactionlessDatabase()) {
            return;
        }

        try {
            $connection = DB::connection();
        } catch (Throwable) {
            return;
        }

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $tables = implode(', ', array_map(
            static fn (string $table): string => '"'.$table.'"',
            self::ACCUMULATING_CENTRAL_TABLES,
        ));

        try {
            $connection->statement(sprintf('TRUNCATE TABLE %s RESTART IDENTITY CASCADE', $tables));
        } catch (Throwable) {
            // A schema variant that lacks one of these tables (e.g. a focused
            // run before later migrations) must not break teardown.
        }
    }

    /**
     * Whether this test opted out of RefreshDatabase's per-test transaction
     * (`$connectionsToTransact = []`), making it accumulate committed rows.
     */
    private function usesTransactionlessDatabase(): bool
    {
        if (! property_exists($this, 'connectionsToTransact')) {
            return false;
        }

        return $this->connectionsToTransact === [];
    }
}
