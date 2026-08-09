<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Services\TenantDatabaseEnsurer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Client\Models\Client;
use Modules\Interaction\Models\InteractionStatus;
use Modules\Interaction\Models\InteractionType;
use Modules\Tenant\Database\Seeders\TenantDatabaseSeeder;
use Modules\Tenant\Models\Tenant;
use Modules\Workflow\Models\Workflow;
use Modules\Workflow\Models\WorkflowStage;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

final class TenantSeedDefaultsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Exclude the pgsql connection from RefreshDatabase's transaction wrapping.
     *
     * TenantDatabaseEnsurer::ensure() calls DB::purge() on the central connection
     * to reconnect after creating the tenant database. If the central connection is
     * inside a transaction, DB::purge() closes the PDO connection and PostgreSQL
     * rolls back the uncommitted INSERT — the tenant row disappears. By excluding
     * pgsql from transactions, inserts commit immediately and remain visible to
     * subsequent Artisan::call() invocations that query the central DB.
     *
     * Cleanup is handled explicitly in tearDown().
     *
     * @var array<string>
     */
    protected $connectionsToTransact = [];

    /**
     * Creates a Tenant row and provisions its database (migrate only, no seed).
     * Uses Event::fakeFor() to suppress only the TenantCreated Stancl event,
     * preventing the automatic TenantDatabaseEnsurer invocation while keeping
     * all Eloquent model events active so the INSERT is executed normally.
     */
    private function makeTenant(bool $seed = false): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = Event::fakeFor(
            static function (): Tenant {
                return Tenant::create([
                    'id' => (string) Str::uuid(),
                    'name' => 'test-seed-tenant-'.bin2hex(random_bytes(4)),
                    'tenancy_db_name' => 'tenant_test_'.bin2hex(random_bytes(4)),
                    'status' => Tenant::STATUS_ACTIVO,
                ]);
            },
            [TenantCreated::class],
        );

        TenantDatabaseEnsurer::ensure($tenant, seed: false);

        if ($seed) {
            $tenant->run(function (): void {
                Artisan::call('db:seed', [
                    '--class' => TenantDatabaseSeeder::class,
                    '--force' => true,
                ]);
            });
        }

        return $tenant;
    }

    public function test_fresh_tenant_has_exactly_one_default_workflow(): void
    {
        $tenant = $this->makeTenant(seed: true);

        $tenant->run(function (): void {
            $this->assertSame(1, Workflow::query()->count());
            $this->assertSame('Pipeline de Ventas', Workflow::query()->value('name'));
        });
    }

    public function test_fresh_tenant_has_six_workflow_stages_in_spanish_with_correct_types(): void
    {
        $tenant = $this->makeTenant(seed: true);

        $tenant->run(function (): void {
            $stages = WorkflowStage::query()->orderBy('order')->get();
            $this->assertCount(6, $stages);
            $this->assertSame(
                ['Nuevo', 'Calificado', 'Propuesta', 'Negociación', 'Ganado', 'Perdido'],
                $stages->pluck('name')->all(),
            );
            $this->assertSame(
                ['open', 'open', 'open', 'open', 'won', 'lost'],
                $stages->pluck('type')->all(),
            );
        });
    }

    public function test_fresh_tenant_has_three_interaction_types_and_four_interaction_statuses(): void
    {
        $tenant = $this->makeTenant(seed: true);

        $tenant->run(function (): void {
            $this->assertSame(3, InteractionType::query()->count());
            $this->assertSame(4, InteractionStatus::query()->count());
        });
    }

    public function test_seeder_is_idempotent_across_two_runs(): void
    {
        $tenant = $this->makeTenant(seed: true);
        $tenant->run(function (): void {
            Artisan::call('db:seed', [
                '--class' => TenantDatabaseSeeder::class,
                '--force' => true,
            ]);
        });

        $tenant->run(function (): void {
            $this->assertSame(1, Workflow::query()->count());
            $this->assertSame(6, WorkflowStage::query()->count());
            $this->assertSame(3, InteractionType::query()->count());
            $this->assertSame(4, InteractionStatus::query()->count());
        });
    }

    public function test_seeder_does_not_create_client_rows(): void
    {
        $tenant = $this->makeTenant(seed: true);

        $tenant->run(function (): void {
            $this->assertSame(0, Client::query()->count());
        });
    }

    public function test_backfill_command_seeds_only_missing_rows(): void
    {
        $tenant = $this->makeTenant(seed: false);

        $tenant->run(function (): void {
            InteractionType::create([
                'name' => 'Llamada',
                'color' => '#000000',
                'description' => 'pre-existing',
            ]);
        });

        $exit = Artisan::call('tenants:backfill-defaults', ['--tenant' => [$tenant->id]]);
        $output = Artisan::output();
        $this->assertSame(0, $exit, "Backfill failed. Output:\n{$output}");

        $tenant->run(function (): void {
            $this->assertSame(1, Workflow::query()->count());
            $this->assertSame(6, WorkflowStage::query()->count());
            $this->assertSame(3, InteractionType::query()->count());
            $this->assertSame(4, InteractionStatus::query()->count());
        });
    }

    protected function tearDown(): void
    {
        // End tenancy context if still active so the central connection is usable.
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        // Drop tenant databases and delete tenant rows created by this test suite.
        // Use withoutEvents on forceDelete to prevent the TenantDeleted listener
        // from firing Jobs\DeleteDatabase (which would try to drop an already-gone DB).
        Tenant::withTrashed()
            ->where('name', 'like', 'test-seed-tenant-%')
            ->get()
            ->each(static function (Tenant $tenant): void {
                try {
                    $manager = $tenant->database()->manager();
                    if ($manager && $manager->databaseExists($tenant->database()->getName())) {
                        $manager->deleteDatabase($tenant);
                    }
                } catch (\Throwable) {
                    // Database may not exist if the test failed before provisioning it.
                }
                Tenant::withoutEvents(static fn () => $tenant->forceDelete());
            });

        parent::tearDown();
    }
}
