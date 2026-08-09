<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Notification\Models\NotificationUser;
use Modules\Package\Models\Package;
use Modules\Package\Models\PackageStatus;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Coverage for ProcessPackageRetentionCommand (WU-4 / package-retention spec):
 *
 *  - Command is registered in schedule:list (dailyAt 08:00)
 *  - Reminder sent once when reminder_after_days elapsed and notified_at is set
 *  - reminded_at stamped after first reminder, blocking duplicate dispatch
 *  - Package transitioned to RETURNED when return_after_days elapsed
 *  - Picked-up packages are ignored regardless of age (command queries READY only)
 *  - Tenant isolation: Tenant A overdue → returned; Tenant B within retention → READY
 */
final class PackageRetentionTest extends TestCase
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

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeUnit(Tenant $tenant): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit): void {
            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'number' => (string) mt_rand(1, 99999),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);
        });

        return $unit;
    }

    private function makeResident(Tenant $tenant): User
    {
        $user = User::create([
            'name' => 'Resident '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => bcrypt('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        return $user;
    }

    private function linkToUnit(Tenant $tenant, Unit $unit, User $user): void
    {
        $tenant->run(function () use ($unit, $user): void {
            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ]);
        });
    }

    /**
     * Create a package directly in the DB for tests that need precise control over
     * received_at / notified_at / reminded_at without going through PackageService.
     *
     * tenant_id is auto-stamped by BelongsToTenant when called inside $tenant->run().
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createPackage(Tenant $tenant, Unit $unit, array $overrides = []): Package
    {
        $pkg = null;
        $tenant->run(function () use ($unit, $overrides, &$pkg): void {
            $pkg = Package::create(array_merge([
                'unit_id' => $unit->id,
                'package_status_id' => PackageStatus::READY,
                'received_at' => now(),
                'notified_at' => null,
                'reminded_at' => null,
            ], $overrides));
        });

        return $pkg;
    }

    // =========================================================================
    // Schedule registration (4.4.1 item 1)
    // =========================================================================

    public function test_command_is_registered_in_schedule_at_daily_08(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertStringContainsString(
            'package:process-retention',
            $output,
            'package:process-retention must appear in the scheduler output'
        );

        // dailyAt('08:00') produces the cron expression "0 8 * * *" (fields may be separated
        // by variable whitespace in the schedule:list rendering). Verify the hour=8 field
        // appears alongside the command name in the output line.
        // strip ANSI codes before asserting so colour sequences don't interfere.
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $output) ?? $output;
        $this->assertMatchesRegularExpression(
            '/0\s+8\s+\*\s+\*\s+\*.*package:process-retention|package:process-retention.*0\s+8\s+\*\s+\*\s+\*/',
            $plain,
            'The cron expression for dailyAt(08:00) must appear near package:process-retention'
        );
    }

    // =========================================================================
    // Reminder threshold (4.4.1 item 2)
    // =========================================================================

    public function test_package_at_reminder_threshold_sends_reminder_and_stamps_reminded_at(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeResident($tenant);
        $unit = $this->makeUnit($tenant);
        $this->linkToUnit($tenant, $unit, $resident);

        // Package received 3 days ago; arrival notification was already sent (notified_at set).
        // reminder_after_days defaults to 3 (from TenantCatalogSeeder).
        $pkg = $this->createPackage($tenant, $unit, [
            'received_at' => now()->subDays(3),
            'notified_at' => now()->subDays(3),
            'reminded_at' => null,
        ]);

        $notificationCountBefore = 0;
        $tenant->run(function () use (&$notificationCountBefore): void {
            $notificationCountBefore = NotificationUser::count();
        });

        $this->artisan('package:process-retention')->assertSuccessful();

        $tenant->run(function () use ($pkg, $notificationCountBefore): void {
            $fresh = Package::findOrFail($pkg->id);

            $this->assertNotNull($fresh->reminded_at, 'reminded_at must be stamped after sending reminder');
            $this->assertSame(PackageStatus::READY, $fresh->package_status_id, 'Status must remain READY — only return changes status');

            $this->assertGreaterThan(
                $notificationCountBefore,
                NotificationUser::count(),
                'A NotificationUser row must be created for the resident when reminder fires'
            );
        });
    }

    // =========================================================================
    // Duplicate reminder guard (4.4.1 item 3)
    // =========================================================================

    public function test_package_with_reminded_at_set_does_not_receive_second_reminder(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeResident($tenant);
        $unit = $this->makeUnit($tenant);
        $this->linkToUnit($tenant, $unit, $resident);

        // Day 5: beyond reminder threshold but reminded_at already set (dedup guard).
        $pkg = $this->createPackage($tenant, $unit, [
            'received_at' => now()->subDays(5),
            'notified_at' => now()->subDays(5),
            'reminded_at' => now()->subDays(2), // reminder was already sent on day 3
        ]);

        $notificationCountBefore = 0;
        $originalRemindedAt = null;
        $tenant->run(function () use ($pkg, &$notificationCountBefore, &$originalRemindedAt): void {
            $notificationCountBefore = NotificationUser::count();
            $originalRemindedAt = Package::findOrFail($pkg->id)->reminded_at;
        });

        $this->artisan('package:process-retention')->assertSuccessful();

        $tenant->run(function () use ($pkg, $notificationCountBefore, $originalRemindedAt): void {
            $fresh = Package::findOrFail($pkg->id);

            // reminded_at must not have changed — dedup guard prevents a second write.
            $this->assertNotNull($fresh->reminded_at);
            $this->assertSame(
                $originalRemindedAt?->toIso8601String(),
                $fresh->reminded_at->toIso8601String(),
                'reminded_at must not change on a second run'
            );

            $this->assertSame(
                $notificationCountBefore,
                NotificationUser::count(),
                'No new NotificationUser rows when reminded_at is already set'
            );
        });
    }

    // =========================================================================
    // Return threshold (4.4.1 item 4)
    // =========================================================================

    public function test_package_past_return_threshold_is_transitioned_to_returned(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);

        // Day 11 > return_after_days=10 (default from TenantCatalogSeeder).
        $pkg = $this->createPackage($tenant, $unit, [
            'received_at' => now()->subDays(11),
            'notified_at' => now()->subDays(11),
        ]);

        $this->artisan('package:process-retention')->assertSuccessful();

        $tenant->run(function () use ($pkg): void {
            $fresh = Package::findOrFail($pkg->id);

            $this->assertSame(
                PackageStatus::RETURNED,
                $fresh->package_status_id,
                'Package must transition to RETURNED after return_after_days have elapsed'
            );
            $this->assertSame(
                'Plazo de retención expirado',
                $fresh->incident_reason,
                'incident_reason must record the system-initiated reason'
            );
        });
    }

    // =========================================================================
    // Terminal statuses are skipped (4.4.1 item 5)
    // =========================================================================

    public function test_picked_up_package_is_skipped_regardless_of_age(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);

        // Package is already PICKED_UP but 30 days old (far beyond both thresholds).
        // The command's WHERE only targets READY packages, so this must never be touched.
        $pkg = $this->createPackage($tenant, $unit, [
            'received_at' => now()->subDays(30),
            'notified_at' => now()->subDays(30),
            'reminded_at' => null,
            'package_status_id' => PackageStatus::PICKED_UP,
        ]);

        $this->artisan('package:process-retention')->assertSuccessful();

        $tenant->run(function () use ($pkg): void {
            $fresh = Package::findOrFail($pkg->id);

            $this->assertSame(PackageStatus::PICKED_UP, $fresh->package_status_id, 'PICKED_UP package must never be touched by retention cron');
            $this->assertNull($fresh->reminded_at, 'reminded_at must remain null — package was already picked up');
        });
    }

    // =========================================================================
    // Tenant isolation (4.4.1 item 6)
    // =========================================================================

    public function test_tenant_a_overdue_returned_while_tenant_b_within_retention_stays_ready(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $unitA = $this->makeUnit($tenantA);
        $unitB = $this->makeUnit($tenantB);

        // Tenant A: 11 days old → past return_after_days (10) → must be RETURNED.
        $pkgA = $this->createPackage($tenantA, $unitA, [
            'received_at' => now()->subDays(11),
            'notified_at' => now()->subDays(11),
        ]);

        // Tenant B: 5 days old → within retention (return_after_days=10) → must remain READY.
        $pkgB = $this->createPackage($tenantB, $unitB, [
            'received_at' => now()->subDays(5),
            'notified_at' => now()->subDays(5),
        ]);

        $this->artisan('package:process-retention')->assertSuccessful();

        $tenantA->run(function () use ($pkgA): void {
            $this->assertSame(
                PackageStatus::RETURNED,
                Package::findOrFail($pkgA->id)->package_status_id,
                'Tenant A overdue package must transition to RETURNED'
            );
        });

        $tenantB->run(function () use ($pkgB): void {
            $this->assertSame(
                PackageStatus::READY,
                Package::findOrFail($pkgB->id)->package_status_id,
                'Tenant B package within retention window must remain READY'
            );
        });
    }
}
