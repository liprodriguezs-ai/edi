<?php

declare(strict_types=1);

namespace Modules\Reports\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Notification\Models\Notification;
use Modules\Reports\Models\ReportFrequency;
use Modules\Reports\Models\ScheduledReport;
use Modules\Tenant\Models\Tenant;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-RPT-013 — the nightly scheduled-report runner.
 *
 * The property that matters is idempotency: this command runs from cron, and a
 * retry, an overlapping run, or a manual invocation must not deliver the same
 * report twice in one period. `last_run_at` is the anchor, and it is not
 * fillable precisely so nothing but the runner can move it.
 */
final class RunScheduledReportsTest extends TestCase
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

    private function scheduleFor(Tenant $tenant, string $frequencyId, ?string $lastRunAt = null): ScheduledReport
    {
        return $this->inTenant($tenant, function () use ($tenant, $frequencyId, $lastRunAt): ScheduledReport {
            $user = $this->makeUser($tenant);

            $report = ScheduledReport::create([
                'name' => 'Tamizajes del periodo',
                'report_model_key' => 'Screening',
                'params' => [],
                'report_frequency_id' => $frequencyId,
                'recipient_user_id' => $user->id,
                'is_active' => true,
            ]);

            if ($lastRunAt !== null) {
                $report->forceFill(['last_run_at' => $lastRunAt])->saveQuietly();
            }

            return $report;
        });
    }

    private function notificationCount(): int
    {
        return Notification::query()->count();
    }

    public function test_a_daily_report_that_never_ran_is_delivered(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $schedule = $this->scheduleFor($tenant, ReportFrequency::DAILY);
        $before = $this->notificationCount();

        Artisan::call('reports:run-scheduled');

        $this->assertGreaterThan($before, $this->notificationCount(), 'The due report was never delivered.');
        $this->inTenant($tenant, function () use ($schedule): void {
            $this->assertNotNull($schedule->fresh()->last_run_at, 'The runner did not stamp last_run_at.');
        });
    }

    public function test_running_twice_the_same_day_delivers_once(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $this->scheduleFor($tenant, ReportFrequency::DAILY);

        Artisan::call('reports:run-scheduled');
        $afterFirst = $this->notificationCount();

        Artisan::call('reports:run-scheduled');

        $this->assertSame($afterFirst, $this->notificationCount(), 'A rerun on the same day delivered the report again.');
    }

    public function test_a_monthly_report_run_this_month_is_not_due_again(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $this->scheduleFor($tenant, ReportFrequency::MONTHLY, now()->startOfMonth()->addDay()->toDateTimeString());

        // Delta, not absolute: creating an organization already seeds notifications
        // of its own, so a bare count would never start at zero.
        $before = $this->notificationCount();

        Artisan::call('reports:run-scheduled');

        $this->assertSame($before, $this->notificationCount(), 'A monthly report already run this month was delivered again.');
    }

    public function test_a_paused_schedule_is_skipped(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $schedule = $this->scheduleFor($tenant, ReportFrequency::DAILY);
        $this->inTenant($tenant, fn () => $schedule->forceFill(['is_active' => false])->saveQuietly());

        $before = $this->notificationCount();

        Artisan::call('reports:run-scheduled');

        $this->assertSame($before, $this->notificationCount(), 'A paused schedule was still delivered.');
    }

    /**
     * Each schedule must generate inside its OWN organization: the runner switches
     * tenants per row, so a report can never span organizations.
     */
    public function test_each_organization_gets_only_its_own_schedule(): void
    {
        [$tenantA, , $tenantB] = $this->twoTenants();
        $this->scheduleFor($tenantA, ReportFrequency::DAILY);

        Artisan::call('reports:run-scheduled');

        $countB = $this->inTenant($tenantB, fn () => ScheduledReport::query()->count());
        $this->assertSame(0, $countB, 'Another organization saw a schedule that is not theirs.');
    }

    public function test_daily_due_ness_follows_the_tenant_civil_day(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $tenant->update(['timezone' => 'America/Mexico_City']);
        // Ran at Oct 20 06:00 Mexico; the clock reads Oct 20 21:00 Mexico (Oct 21 03:00 UTC).
        $this->scheduleFor($tenant, ReportFrequency::DAILY, '2026-10-20 12:00:00');
        $this->travelTo(Carbon::parse('2026-10-21T03:00:00Z'));
        $before = $this->notificationCount();

        Artisan::call('reports:run-scheduled');
        $this->assertSame($before, $this->notificationCount(), 'Same tenant day: the report must not run again.');

        $this->travelTo(Carbon::parse('2026-10-21T06:30:00Z'));
        Artisan::call('reports:run-scheduled');
        $this->assertGreaterThan($before, $this->notificationCount(), 'A new tenant day began: the report is due.');
    }
}
