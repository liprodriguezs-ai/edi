<?php

declare(strict_types=1);

namespace Modules\Reports\Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\ModuleTableSeeder;
use Database\Seeders\PermissionsTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Modules\Reports\Models\ReportFrequency;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * The scheduled-report CRUD is gated by `*.scheduled_report` abilities. Those
 * permissions have to EXIST — a route guarded by a permission nobody was ever
 * granted answers 403 to everyone, which looks exactly like a permissions policy
 * and not like a missing seed row.
 */
final class ScheduledReportCrudTest extends TestCase
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

    public function test_the_scheduled_report_permissions_are_seeded(): void
    {
        // The permission rows come from the CSV chain, not from tenant seeding:
        // that is exactly what this asserts.
        Artisan::call('db:seed', ['--class' => ModuleTableSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => PermissionsTableSeeder::class, '--force' => true]);

        foreach (['read', 'create', 'update', 'delete'] as $verb) {
            $this->assertTrue(
                Permission::query()->where('name', "{$verb}.scheduled_report")->exists(),
                "The {$verb}.scheduled_report permission is missing, so its route can only ever answer 403."
            );
        }
    }

    public function test_a_user_holding_the_ability_can_create_and_list_a_schedule(): void
    {
        [$tenantA] = $this->twoTenants();

        $abilities = ['read.scheduled_report', 'create.scheduled_report'];

        $role = Role::create(['name' => 'scheduler-'.Str::random(8), 'guard_name' => 'api']);
        foreach ($abilities as $ability) {
            $role->givePermissionTo(Permission::findOrCreate($ability, 'api'));
        }

        $user = $this->makeUser($tenantA);
        $user->assignRole($role->name);
        $token = $user->createToken('scheduler', $abilities)->plainTextToken;

        $this->tenantRequest($token, $tenantA)
            ->postJson('/api/reports/scheduled', [
                'name' => 'Tamizajes del mes',
                'report_model_key' => 'Screening',
                'report_frequency_id' => ReportFrequency::MONTHLY,
                'recipient_user_id' => $user->id,
            ])
            ->assertCreated();

        $this->tenantRequest($token, $tenantA)
            ->getJson('/api/reports/scheduled')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Tamizajes del mes');
    }

    /**
     * The recipient must belong to the same organization, or a schedule could
     * deliver one organization's export to a stranger.
     */
    public function test_a_recipient_from_another_organization_is_rejected(): void
    {
        [$tenantA, , $tenantB] = $this->twoTenants();

        $abilities = ['read.scheduled_report', 'create.scheduled_report'];
        $role = Role::create(['name' => 'scheduler-'.Str::random(8), 'guard_name' => 'api']);
        foreach ($abilities as $ability) {
            $role->givePermissionTo(Permission::findOrCreate($ability, 'api'));
        }

        $user = $this->makeUser($tenantA);
        $user->assignRole($role->name);
        $token = $user->createToken('scheduler', $abilities)->plainTextToken;

        $outsider = $this->makeUser($tenantB);

        $this->tenantRequest($token, $tenantA)
            ->postJson('/api/reports/scheduled', [
                'name' => 'Fuga',
                'report_model_key' => 'Screening',
                'report_frequency_id' => ReportFrequency::MONTHLY,
                'recipient_user_id' => $outsider->id,
            ])
            ->assertStatus(422);
    }
}
