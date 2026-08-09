<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Projects\Models\CommunityProject;
use Modules\Projects\Models\CommunityProjectStatus;
use Modules\Projects\Models\ProjectContributionStatus;
use Modules\Projects\Services\ProjectsReportService;
use Modules\Tenant\Models\Tenant;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Feature tests for ProjectsReportController.
 *
 * Covers: GET /projects/funding-summary  (read.report_projects)
 *
 * Tests verify pct calculation, goal=0 guard, by_status grouping,
 * tenant isolation, and 401/403 guards.
 */
final class ProjectsReportControllerTest extends TestCase
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

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** @param  array<int, string>  $abilities */
    private function makeToken(Tenant $tenant, array $abilities): string
    {
        return $this->makeUser($tenant)->createToken('proj-report', $abilities)->plainTextToken;
    }

    /**
     * Create a CommunityProject with a known goal_amount (in minor units) and
     * a specific status, then add ProjectContribution rows summing to
     * $raisedMinor.
     */
    private function makeProjectWithContributions(
        Tenant $tenant,
        int $goalMinor,
        int $raisedMinor,
        string $statusId,
    ): CommunityProject {
        $project = null;

        $tenant->run(static function () use (&$project, $goalMinor, $statusId): void {
            // Required FK columns — use first seeded catalog rows.
            $categoryId = DB::table('community_project_category')->value('id');
            $participationTypeId = DB::table('participation_type')->value('id');
            $amountModeId = DB::table('amount_mode')->value('id');

            $project = CommunityProject::create([
                'title' => 'Proyecto '.Str::random(5),
                'goal_amount' => $goalMinor,
                'community_project_status_id' => $statusId,
                'community_project_category_id' => $categoryId,
                'participation_type_id' => $participationTypeId,
                'amount_mode_id' => $amountModeId,
                'visible_to_residents' => true,
                'allow_comments' => false,
                'allow_decline' => false,
            ]);
        });

        // Add a contribution equal to raisedMinor (if non-zero).
        if ($raisedMinor > 0) {
            $tenant->run(static function () use ($project, $raisedMinor): void {
                // unit_id is NOT NULL in project_contribution.
                $unitId = DB::table('unit')->value('id');
                if (! $unitId) {
                    $unitId = (string) Str::uuid();
                    DB::table('unit')->insert([
                        'id' => $unitId,
                        'number' => 'C'.Str::random(3),
                        'unit_type_id' => DB::table('unit_type')->value('id'),
                        'unit_status_id' => DB::table('unit_status')->value('id'),
                        'tenant_id' => tenancy()->tenant->id,
                        'user_created_id' => null,
                        'user_updated_id' => null,
                        'created_at' => now()->toDateTimeString(),
                        'updated_at' => now()->toDateTimeString(),
                    ]);
                }

                DB::table('project_contribution')->insert([
                    'id' => (string) Str::uuid(),
                    'community_project_id' => $project->id,
                    'unit_id' => $unitId,
                    'project_contribution_status_id' => ProjectContributionStatus::IDEAL,
                    'expected_amount' => $raisedMinor,
                    'contributed_amount' => $raisedMinor,
                    'declined' => false,
                    'tenant_id' => tenancy()->tenant->id,
                    'user_created_id' => null,
                    'user_updated_id' => null,
                    'created_at' => now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString(),
                ]);
            });
        }

        return $project;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function test_funding_summary_returns_correct_structure(): void
    {
        $tenantA = $this->makeTenant('Cluster Proj');
        $this->seedTenant($tenantA);
        $token = $this->makeToken($tenantA, ['read.report_projects']);

        $response = $this->tenantRequest($token, $tenantA)
            ->getJson('/api/projects/funding-summary');

        $response->assertOk();
        $data = $response->json();

        $this->assertArrayHasKey('projects', $data);
        $this->assertArrayHasKey('totals', $data);
        $this->assertArrayHasKey('by_status', $data);

        $totals = $data['totals'];
        $this->assertArrayHasKey('active_count', $totals);
        $this->assertArrayHasKey('goal', $totals);
        $this->assertArrayHasKey('raised', $totals);
        $this->assertArrayHasKey('pct', $totals);
        $this->assertArrayHasKey('amount', $totals['goal']);
        $this->assertArrayHasKey('currency', $totals['goal']);
    }

    public function test_pct_calculation_is_correct_for_known_values(): void
    {
        $tenantA = $this->makeTenant('Cluster PctCalc');
        $this->seedTenant($tenantA);
        $token = $this->makeToken($tenantA, ['read.report_projects']);

        // goal=100000, raised=45000 → pct=45
        $this->makeProjectWithContributions($tenantA, 100000, 45000, CommunityProjectStatus::ACTIVE);

        $response = $this->tenantRequest($token, $tenantA)
            ->getJson('/api/projects/funding-summary');

        $response->assertOk();
        $projects = $response->json('projects');
        $this->assertCount(1, $projects);

        $project = $projects[0];
        $this->assertSame('100000', $project['goal']['amount']);
        $this->assertSame('MXN', $project['goal']['currency']);
        $this->assertSame('45000', $project['raised']['amount']);
        $this->assertSame(45, $project['pct']);
        $this->assertIsString($project['goal']['amount']);
        $this->assertIsString($project['raised']['amount']);
        $this->assertIsInt($project['pct']);
    }

    public function test_goal_zero_produces_pct_zero_no_division_error(): void
    {
        $tenantA = $this->makeTenant('Cluster ZeroGoal');
        $this->seedTenant($tenantA);
        $token = $this->makeToken($tenantA, ['read.report_projects']);

        // goal=0, raised=0 → pct=0 (no division by zero).
        $this->makeProjectWithContributions($tenantA, 0, 0, CommunityProjectStatus::ACTIVE);

        $response = $this->tenantRequest($token, $tenantA)
            ->getJson('/api/projects/funding-summary');

        $response->assertOk();
        $project = $response->json('projects.0');
        $this->assertSame('0', $project['goal']['amount']);
        $this->assertSame('0', $project['raised']['amount']);
        $this->assertSame(0, $project['pct']);
    }

    public function test_by_status_contains_active_entry_with_count(): void
    {
        $tenantA = $this->makeTenant('Cluster ByStatus');
        $this->seedTenant($tenantA);
        $token = $this->makeToken($tenantA, ['read.report_projects']);

        // Create 3 active projects.
        for ($i = 0; $i < 3; $i++) {
            $this->makeProjectWithContributions($tenantA, 100000, 0, CommunityProjectStatus::ACTIVE);
        }

        $response = $this->tenantRequest($token, $tenantA)
            ->getJson('/api/projects/funding-summary');

        $response->assertOk();
        $byStatus = $response->json('by_status');

        $activeItem = collect($byStatus)->firstWhere('status_key', 'active');
        $this->assertNotNull($activeItem, 'by_status must contain active entry');
        $this->assertSame(3, $activeItem['count']);
    }

    public function test_totals_active_count_reflects_active_projects(): void
    {
        $tenantA = $this->makeTenant('Cluster TotalsActive');
        $this->seedTenant($tenantA);
        $token = $this->makeToken($tenantA, ['read.report_projects']);

        $this->makeProjectWithContributions($tenantA, 200000, 100000, CommunityProjectStatus::ACTIVE);
        $this->makeProjectWithContributions($tenantA, 50000, 50000, CommunityProjectStatus::FULLY_FUNDED);

        $response = $this->tenantRequest($token, $tenantA)
            ->getJson('/api/projects/funding-summary');

        $response->assertOk();
        $totals = $response->json('totals');

        $this->assertSame(1, $totals['active_count']);
        $this->assertSame('250000', $totals['goal']['amount']);
        $this->assertSame('150000', $totals['raised']['amount']);
    }

    public function test_tenant_b_project_absent_from_tenant_a_response(): void
    {
        $tenantA = $this->makeTenant('Cluster ProjA');
        $tenantB = $this->makeTenant('Cluster ProjB');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);
        $token = $this->makeToken($tenantA, ['read.report_projects']);

        $this->makeProjectWithContributions($tenantA, 100000, 45000, CommunityProjectStatus::ACTIVE);
        $this->makeProjectWithContributions($tenantB, 999000, 999000, CommunityProjectStatus::ACTIVE);

        $response = $this->tenantRequest($token, $tenantA)
            ->getJson('/api/projects/funding-summary');

        $response->assertOk();
        // Only tenant A project should be in the response.
        $this->assertCount(1, $response->json('projects'));
        $this->assertSame('100000', $response->json('projects.0.goal.amount'));
    }

    public function test_funding_summary_requires_authentication(): void
    {
        $this->getJson('/api/projects/funding-summary')->assertStatus(401);
    }

    public function test_funding_summary_requires_permission(): void
    {
        $tenantA = $this->makeTenant('Cluster ProjPerm');
        $this->seedTenant($tenantA);
        $wrongToken = $this->makeToken($tenantA, ['read.community_project']);

        $this->tenantRequest($wrongToken, $tenantA)
            ->getJson('/api/projects/funding-summary')
            ->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cache behaviour
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cache::remember is invoked with a tenant-scoped key when caching is enabled.
     *
     * Design note: Stancl's CacheTenancyBootstrapper replaces the array cache
     * store binding on every tenancy initialize, so cross-request stale-data
     * assertions are unreliable with the array driver in feature tests. We
     * verify the key format via the service's buildCacheKey helper directly.
     */
    public function test_funding_summary_cache_key_contains_tenant_id(): void
    {
        config(['dashboard.reports.cache_enabled' => true, 'dashboard.reports.cache_ttl' => 60]);

        $tenantA = $this->makeTenant('Cluster ProjCacheKey');
        $this->seedTenant($tenantA);
        $token = $this->makeToken($tenantA, ['read.report_projects']);

        $response = $this->tenantRequest($token, $tenantA)
            ->getJson('/api/projects/funding-summary');
        $response->assertOk();

        $tenantId = $tenantA->id;

        $tenantA->run(function () use ($tenantId): void {
            $service = app(ProjectsReportService::class);

            $method = new \ReflectionMethod($service, 'buildCacheKey');
            $method->setAccessible(true);
            $key = $method->invoke($service, $tenantId, 'funding_summary', []);

            $this->assertStringStartsWith('dashboard_report:', $key);
            $this->assertStringContainsString($tenantId, $key);
            $this->assertStringContainsString('funding_summary', $key);
        });
    }

    /**
     * Tenant A and Tenant B have independent cache partitions.
     */
    public function test_funding_summary_cache_is_tenant_scoped(): void
    {
        config(['dashboard.reports.cache_enabled' => true, 'dashboard.reports.cache_ttl' => 60]);
        Cache::flush();

        $tenantA = $this->makeTenant('Cluster ProjScopeA');
        $tenantB = $this->makeTenant('Cluster ProjScopeB');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $tokenA = $this->makeToken($tenantA, ['read.report_projects']);
        $tokenB = $this->makeToken($tenantB, ['read.report_projects']);

        // 1 project in A, 3 projects in B.
        $this->makeProjectWithContributions($tenantA, 100000, 0, CommunityProjectStatus::ACTIVE);
        for ($i = 0; $i < 3; $i++) {
            $this->makeProjectWithContributions($tenantB, 50000, 0, CommunityProjectStatus::ACTIVE);
        }

        $respA = $this->tenantRequest($tokenA, $tenantA)->getJson('/api/projects/funding-summary');
        $respB = $this->tenantRequest($tokenB, $tenantB)->getJson('/api/projects/funding-summary');

        $respA->assertOk();
        $respB->assertOk();

        // Each tenant must only see its own projects.
        $this->assertCount(1, $respA->json('projects'), 'Tenant A must see only its own 1 project');
        $this->assertCount(3, $respB->json('projects'), 'Tenant B must see only its own 3 projects');
    }

    /**
     * When cache is disabled, every call returns fresh (current) data.
     */
    public function test_funding_summary_cache_disabled_returns_fresh_data(): void
    {
        config(['dashboard.reports.cache_enabled' => false]);
        Cache::flush();

        $tenantA = $this->makeTenant('Cluster ProjCacheOff');
        $this->seedTenant($tenantA);
        $token = $this->makeToken($tenantA, ['read.report_projects']);

        $this->makeProjectWithContributions($tenantA, 100000, 0, CommunityProjectStatus::ACTIVE);

        $first = $this->tenantRequest($token, $tenantA)
            ->getJson('/api/projects/funding-summary');
        $first->assertOk();
        $this->assertCount(1, $first->json('projects'));

        // Add second project — cache off, must appear immediately.
        $this->makeProjectWithContributions($tenantA, 200000, 0, CommunityProjectStatus::DRAFT);

        $second = $this->tenantRequest($token, $tenantA)
            ->getJson('/api/projects/funding-summary');
        $second->assertOk();
        $this->assertCount(
            2,
            $second->json('projects'),
            'Cache disabled: fresh data must reflect new project',
        );
    }
}
