<?php

declare(strict_types=1);

namespace Modules\ChildRecord\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Audit\Models\Audit;
use Modules\ChildRecord\Models\Child;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-XC-005 — audit trail without log leakage.
 *
 * Two project-wide behaviors this test must work around, neither specific to
 * ChildRecord:
 *  - owen-it/laravel-auditing ships `'console' => false` (config/audit.php):
 *    automatic created/updated/deleted auditing is skipped whenever
 *    `app()->runningInConsole()` is true — which `php artisan test` always is,
 *    even while simulating an HTTP request. A real production request is NOT
 *    console, so this is a test-environment false negative only; overriding
 *    it here verifies the model IS correctly wired for auditing.
 *  - `Audit::save()` defers to `DB::afterCommit()` when inside a transaction,
 *    so this class disables transactional per-test isolation (mirrors the
 *    established pattern in
 *    tests/Feature/Security/{PrivacyTest,AnonymizationTest}.php).
 */
final class ChildAuditTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['audit.console' => true]);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_audit_trail_records_curp_update_without_log_leakage(): void
    {
        $tenant = $this->makeTenant('Child Audit Organization');
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $childId = $this->inTenant($tenant, fn () => Child::factory()->create(['curp' => null])->id);

        $logPath = storage_path('logs/laravel.log');
        $before = is_file($logPath) ? filesize($logPath) : 0;

        $this->tenantRequest($token, $tenant)
            ->putJson('/api/child/'.$childId, ['curp' => 'GASO180514MDFRPN09'])
            ->assertOk();

        $audit = Audit::where('auditable_id', $childId)->where('event', 'updated')->first();
        $this->assertNotNull($audit, 'Updating curp must leave an audit row.');

        // QCH\Core\Models\Audit::save() defers to DB::afterCommit() with a
        // forceFill() on a fresh instance when saved inside a transaction;
        // the re-fetched row's array cast is not always applied on that path
        // (a pre-existing platform quirk, not specific to ChildRecord) — decode
        // defensively so this test verifies the REQUIRED behavior either way.
        $newValues = is_string($audit->new_values) ? json_decode($audit->new_values, true) : $audit->new_values;
        $this->assertSame('GASO180514MDFRPN09', $newValues['curp'] ?? null);

        if (is_file($logPath)) {
            $appended = substr((string) file_get_contents($logPath), $before);
            $this->assertStringNotContainsString('GASO180514MDFRPN09', $appended);
        } else {
            $this->assertTrue(true, 'No log file was written — nothing to leak.');
        }
    }

    public function test_audit_read_is_permission_gated(): void
    {
        $tenant = $this->makeTenant('Child Audit Permission Organization');
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        // No read.audit ability — mirrors "role without read.audit" from REQ-XC-005.
        $token = $user->createToken('test', ['read.child'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/audit')
            ->assertStatus(403);
    }
}
