<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Notification\Models\NotificationUser;
use Modules\Reports\Jobs\GenerateReportJob;
use Modules\Reports\Models\GeneratedReport;
use Modules\Reports\Models\ScheduledReport;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Tests for:
 *  - reports:run-scheduled cron (dispatch + next_run_at advance)
 *  - async report-ready notification
 *  - resident C01 own-statement access (unit_statement)
 *  - staff_report admin/board-only gate
 *  - CSV format
 */
final class ReportsAccessAndScheduleTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
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

    private function makeUser(Tenant $tenant): User
    {
        $user = User::create([
            'name' => 'Test '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        return $user;
    }

    private function makeUnit(Tenant $tenant): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit): void {
            $unit = Unit::create([
                'unit_type_id' => UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'))->id,
                'number' => (string) mt_rand(1, 99999),
                'unit_status_id' => UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'))->id,
            ]);
        });

        return $unit;
    }

    private function attachResident(Tenant $tenant, User $user, Unit $unit): void
    {
        $tenant->run(function () use ($user, $unit): void {
            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
                'is_billing_owner' => true,
                'start_date' => '2026-01-01',
            ]);
        });
    }

    private function adminToken(User $user): string
    {
        return $user->createToken('admin', ['*'])->plainTextToken;
    }

    private function residentToken(User $user, array $abilities = []): string
    {
        return $user->createToken('resident', $abilities)->plainTextToken;
    }

    // =========================================================================
    // A. Scheduled-report cron runner
    // =========================================================================

    public function test_run_scheduled_dispatches_due_report_and_advances_next_run(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $scheduledId = null;

        $tenant->run(function () use (&$scheduledId): void {
            $report = ScheduledReport::create([
                'report_key' => ScheduledReport::KEY_DELINQUENCY,
                'config' => [],
                'frequency' => ScheduledReport::FREQUENCY_DAILY,
                'format' => 'json',
                'recipients' => [],
                'next_run_at' => now()->subHour(), // due
                'is_active' => true,
            ]);

            $scheduledId = $report->id;
            $this->assertSame(0, GeneratedReport::count());
        });

        Artisan::call('reports:run-scheduled');

        $tenant->run(function () use ($scheduledId): void {
            $this->assertGreaterThanOrEqual(1, GeneratedReport::count(), 'A generated report must be created for the due schedule');

            $scheduled = ScheduledReport::findOrFail($scheduledId);
            $this->assertNotNull($scheduled->last_run_at, 'last_run_at must be stamped');
            $this->assertTrue($scheduled->next_run_at->gt(now()), 'next_run_at must be advanced into the future');
        });
    }

    public function test_run_scheduled_ignores_not_due_or_inactive(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            ScheduledReport::create([
                'report_key' => ScheduledReport::KEY_DELINQUENCY,
                'config' => [],
                'frequency' => ScheduledReport::FREQUENCY_DAILY,
                'format' => 'json',
                'next_run_at' => now()->addDay(), // not due
                'is_active' => true,
            ]);

            ScheduledReport::create([
                'report_key' => ScheduledReport::KEY_DELINQUENCY,
                'config' => [],
                'frequency' => ScheduledReport::FREQUENCY_DAILY,
                'format' => 'json',
                'next_run_at' => now()->subHour(), // due but inactive
                'is_active' => false,
            ]);
        });

        Artisan::call('reports:run-scheduled');

        $tenant->run(function (): void {
            $this->assertSame(0, GeneratedReport::count(), 'Not-due and inactive schedules must not produce reports');
        });
    }

    // =========================================================================
    // B. Async report-ready notification
    // =========================================================================

    public function test_async_generate_notifies_requesting_user_when_ready(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);

        $tenant->run(function () use ($admin): void {
            $report = GeneratedReport::create([
                'report_key' => 'account_balances',
                'format' => 'json',
                'config' => [],
                'status' => GeneratedReport::STATUS_PENDING,
                'generated_by_user_id' => $admin->id,
                'expires_at' => now()->addDays(90),
            ]);

            GenerateReportJob::dispatchSync($report);

            $report->refresh();
            $this->assertSame(GeneratedReport::STATUS_READY, $report->status);

            $this->assertSame(
                1,
                NotificationUser::where('user_id', $admin->id)->count(),
                'Requesting user must receive a ready notification'
            );
        });
    }

    // =========================================================================
    // C. Resident own-statement access (C01)
    // =========================================================================

    public function test_resident_can_download_own_unit_statement_pdf(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $resident = $this->makeUser($tenant);
        $unit = $this->makeUnit($tenant);
        $this->attachResident($tenant, $resident, $unit);

        $token = $this->residentToken($resident, ['read.unit']); // NO read.report

        $this->tenantRequest($token, $tenant)
            ->post('/api/reports/unit_statement/generate?format=pdf', ['unit_id' => $unit->id])
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_resident_cannot_access_another_units_statement(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $resident = $this->makeUser($tenant);
        $ownUnit = $this->makeUnit($tenant);
        $otherUnit = $this->makeUnit($tenant);
        $this->attachResident($tenant, $resident, $ownUnit);

        $token = $this->residentToken($resident, ['read.unit']);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/reports/unit_statement/generate?format=pdf', ['unit_id' => $otherUnit->id])
            ->assertStatus(403);
    }

    public function test_resident_cannot_access_other_reports(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $resident = $this->makeUser($tenant);

        $token = $this->residentToken($resident, ['read.unit']);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/reports/credit_balances/generate', [])
            ->assertStatus(403);
    }

    // =========================================================================
    // D. staff_report stays admin/board only (salaries are sensitive)
    // =========================================================================

    public function test_staff_report_is_not_accessible_to_resident(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $resident = $this->makeUser($tenant);
        $unit = $this->makeUnit($tenant);
        $this->attachResident($tenant, $resident, $unit);

        $token = $this->residentToken($resident, ['read.unit']);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/reports/staff_report/generate', [])
            ->assertStatus(403);
    }

    public function test_admin_can_access_staff_report(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);

        $token = $this->adminToken($admin);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/reports/staff_report/generate', [])
            ->assertStatus(200);
    }

    // =========================================================================
    // E. CSV format
    // =========================================================================

    public function test_csv_format_is_accepted(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);

        $token = $this->adminToken($admin);

        $response = $this->tenantRequest($token, $tenant)
            ->post('/api/reports/credit_balances/generate?format=csv', []);

        $response->assertStatus(200);
        $this->assertStringContainsString('csv', strtolower($response->headers->get('Content-Type') ?? ''));
    }

    public function test_unknown_format_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);

        $token = $this->adminToken($admin);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/reports/credit_balances/generate?format=xml', [])
            ->assertStatus(422);
    }
}
