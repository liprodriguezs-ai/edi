<?php

declare(strict_types=1);

namespace Modules\Collections\Tests\Feature\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Collections\Database\Seeders\CollectionsDatabaseSeeder;
use Modules\Collections\Support\DelinquencySettings;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Coverage for the delinquency.* seeder rows (CollectionsDatabaseSeeder) and
 * the one-shot delinquency:backfill-settings command (tasks §2.1-2.3).
 */
final class BackfillDelinquencySettingsCommandTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    private function delinquencySlugs(): array
    {
        return array_keys(DelinquencySettings::all());
    }

    // =========================================================================
    // Seeder — exactly 6 rows, defaults, idempotency
    // =========================================================================

    public function test_seeder_creates_exactly_six_delinquency_rows_per_tenant(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $count = Setting::where('slug', 'like', 'delinquency.%')->count();
            $this->assertSame(6, $count);

            foreach ($this->delinquencySlugs() as $slug) {
                $this->assertNotNull(Setting::where('slug', $slug)->first(), "Missing seeded slug: {$slug}");
            }
        });
    }

    public function test_seeder_seeds_documented_defaults(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $this->assertFalse(Setting::getValue(DelinquencySettings::RESTRICTIONS_ENABLED));
            $this->assertSame(0, Setting::getValue(DelinquencySettings::MIN_OVERDUE_AMOUNT));
            $this->assertFalse(Setting::getValue(DelinquencySettings::BLOCK_PACKAGE_INTAKE));
            $this->assertFalse(Setting::getValue(DelinquencySettings::BLOCK_AMENITIES));
            $this->assertFalse(Setting::getValue(DelinquencySettings::BLOCK_VISITOR_ACCESS));
            $this->assertNotEmpty(Setting::getValue(DelinquencySettings::NOTICE_TEXT));
        });
    }

    public function test_seeder_rerun_never_overwrites_a_customized_value(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            Setting::updateOrCreate(
                ['slug' => DelinquencySettings::RESTRICTIONS_ENABLED],
                ['name' => DelinquencySettings::RESTRICTIONS_ENABLED, 'slug' => DelinquencySettings::RESTRICTIONS_ENABLED, 'value' => true, 'type' => Setting::TYPE_BOOLEAN]
            );

            // Re-run the seeder — must NOT touch the customized value.
            (new CollectionsDatabaseSeeder)->run();

            $this->assertTrue(Setting::getValue(DelinquencySettings::RESTRICTIONS_ENABLED));
        });
    }

    public function test_seeder_restores_a_soft_deleted_row_instead_of_violating_the_unique_index(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $row = Setting::where('slug', DelinquencySettings::BLOCK_AMENITIES)->firstOrFail();
            $row->delete();
            $this->assertTrue($row->fresh()->trashed());

            // Re-running must restore, never firstOrCreate-violate the (tenant_id, slug) index.
            (new CollectionsDatabaseSeeder)->run();

            $restored = Setting::where('slug', DelinquencySettings::BLOCK_AMENITIES)->first();
            $this->assertNotNull($restored);
            $this->assertFalse($restored->trashed());
            $this->assertSame(1, Setting::withTrashed()->where('slug', DelinquencySettings::BLOCK_AMENITIES)->count());
        });
    }

    // =========================================================================
    // Backfill command
    // =========================================================================

    public function test_backfill_command_seeds_missing_rows_for_a_tenant_created_before_this_change(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        // Simulate an existing tenant that predates this change: hard-delete the
        // six rows so they are genuinely absent (not just soft-deleted).
        $tenant->run(function (): void {
            Setting::where('slug', 'like', 'delinquency.%')->forceDelete();
            $this->assertSame(0, Setting::where('slug', 'like', 'delinquency.%')->count());
        });

        Artisan::call('delinquency:backfill-settings');

        $tenant->run(function (): void {
            $this->assertSame(6, Setting::where('slug', 'like', 'delinquency.%')->count());
            $this->assertFalse(Setting::getValue(DelinquencySettings::RESTRICTIONS_ENABLED));
        });
    }

    public function test_backfill_command_does_not_overwrite_a_customized_value(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            Setting::updateOrCreate(
                ['slug' => DelinquencySettings::MIN_OVERDUE_AMOUNT],
                ['name' => DelinquencySettings::MIN_OVERDUE_AMOUNT, 'slug' => DelinquencySettings::MIN_OVERDUE_AMOUNT, 'value' => 750000, 'type' => Setting::TYPE_INTEGER]
            );
        });

        Artisan::call('delinquency:backfill-settings');

        $tenant->run(function (): void {
            $this->assertSame(750_000, Setting::getValue(DelinquencySettings::MIN_OVERDUE_AMOUNT));
        });
    }

    public function test_backfill_command_is_tenant_scoped(): void
    {
        $tenantA = $this->makeTenant('A');
        $tenantB = $this->makeTenant('B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $tenantA->run(function (): void {
            Setting::updateOrCreate(
                ['slug' => DelinquencySettings::RESTRICTIONS_ENABLED],
                ['name' => DelinquencySettings::RESTRICTIONS_ENABLED, 'slug' => DelinquencySettings::RESTRICTIONS_ENABLED, 'value' => true, 'type' => Setting::TYPE_BOOLEAN]
            );
        });

        Artisan::call('delinquency:backfill-settings');

        $tenantA->run(function (): void {
            $this->assertTrue(Setting::getValue(DelinquencySettings::RESTRICTIONS_ENABLED));
        });

        $tenantB->run(function (): void {
            $this->assertFalse(Setting::getValue(DelinquencySettings::RESTRICTIONS_ENABLED));
        });
    }

    public function test_backfill_command_runs_for_every_tenant(): void
    {
        $tenantA = $this->makeTenant('A');
        $tenantB = $this->makeTenant('B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        foreach ([$tenantA, $tenantB] as $tenant) {
            $tenant->run(function (): void {
                Setting::where('slug', 'like', 'delinquency.%')->forceDelete();
            });
        }

        $exitCode = Artisan::call('delinquency:backfill-settings');
        $this->assertSame(0, $exitCode);

        foreach ([$tenantA, $tenantB] as $tenant) {
            $tenant->run(function (): void {
                $this->assertSame(6, Setting::where('slug', 'like', 'delinquency.%')->count());
            });
        }
    }
}
