<?php

declare(strict_types=1);

namespace Tests\Feature\Complaint;

use App\Models\Role as AppRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Complaint\Models\ComplaintCategory;
use Modules\Complaint\Models\ComplaintPriority;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Notification\Models\Notification;
use Modules\Notification\Models\NotificationUser;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Coverage for confidential exclusion by unit linkage (spec "Confidential
 * Exclusion by Unit Linkage"):
 *
 *  - A manager linked (unit_user, active) to the denounced unit is excluded
 *    from list/detail visibility AND from complaint_created recipients
 *  - When against_unit_id is null, no automatic exclusion applies
 */
final class ComplaintExclusionTest extends TestCase
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

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeUser(Tenant $tenant, string $role): User
    {
        $user = User::create([
            'name' => 'Test '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => bcrypt('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        AppRole::firstOrCreate(['name' => $role, 'guard_name' => 'api']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->assignRole($role);

        return $user;
    }

    /** @param array<string> $abilities */
    private function token(User $user, array $abilities): string
    {
        return $user->createToken('test', $abilities)->plainTextToken;
    }

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

    /** @return array<string, string> */
    private function catalogIds(Tenant $tenant): array
    {
        $ids = [];
        $tenant->run(function () use (&$ids): void {
            $ids['category_id'] = ComplaintCategory::first()->id;
            $ids['priority_id'] = ComplaintPriority::first()->id;
        });

        return $ids;
    }

    /** @return array<int, string> recipient user ids of the latest complaint_created notification */
    private function latestComplaintCreatedRecipientIds(Tenant $tenant): array
    {
        return $this->inTenant($tenant, function (): array {
            $notification = Notification::where('title', 'Nueva incidencia registrada')
                ->orderByDesc('created_at')
                ->first();

            if (! $notification) {
                return [];
            }

            return NotificationUser::where('notification_id', $notification->id)
                ->pluck('user_id')
                ->all();
        });
    }

    // =========================================================================
    // Denounced board member excluded
    // =========================================================================

    public function test_denounced_unit_manager_excluded_from_detail_and_index(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'president');
        $treasurer = $this->makeUser($tenant, 'treasurer');
        $catalog = $this->catalogIds($tenant);

        $unit = $this->makeUnit($tenant);
        $this->linkToUnit($tenant, $unit, $treasurer);

        $response = $this->tenantRequest($this->token($president, ['create.complaint']), $tenant)
            ->postJson('/api/complaint', [
                'title' => 'Incidencia contra el tesorero',
                'complaint_category_id' => $catalog['category_id'],
                'complaint_priority_id' => $catalog['priority_id'],
                'against_unit_id' => $unit->id,
                'against_description' => 'Uso indebido de fondos comunitarios',
            ])
            ->assertCreated();

        $complaintId = $response->json('data.id');

        // Treasurer is linked to the denounced unit and is NOT the reporter — excluded.
        $treasurerToken = $this->token($treasurer, ['read.complaint']);

        $this->tenantRequest($treasurerToken, $tenant)
            ->getJson("/api/complaint/{$complaintId}")
            ->assertNotFound();

        $index = $this->tenantRequest($treasurerToken, $tenant)
            ->getJson('/api/complaint')
            ->assertOk()
            ->json('data');

        $this->assertNotContains($complaintId, array_column($index, 'id'));

        // The reporter (president) always sees their own filing, excluded set aside.
        $this->tenantRequest($this->token($president, ['read.complaint']), $tenant)
            ->getJson("/api/complaint/{$complaintId}")
            ->assertOk();
    }

    public function test_denounced_unit_manager_excluded_from_complaint_created_recipients(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'president');
        $treasurer = $this->makeUser($tenant, 'treasurer');
        $catalog = $this->catalogIds($tenant);

        $unit = $this->makeUnit($tenant);
        $this->linkToUnit($tenant, $unit, $treasurer);

        $this->tenantRequest($this->token($president, ['create.complaint']), $tenant)
            ->postJson('/api/complaint', [
                'title' => 'Incidencia contra el tesorero',
                'complaint_category_id' => $catalog['category_id'],
                'complaint_priority_id' => $catalog['priority_id'],
                'against_unit_id' => $unit->id,
                'against_description' => 'Uso indebido de fondos comunitarios',
            ])
            ->assertCreated();

        $recipientIds = $this->latestComplaintCreatedRecipientIds($tenant);

        $this->assertContains($president->id, $recipientIds);
        $this->assertNotContains($treasurer->id, $recipientIds);
    }

    // =========================================================================
    // No unit, no exclusion
    // =========================================================================

    public function test_no_exclusion_applies_when_against_unit_is_null(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'president');
        $treasurer = $this->makeUser($tenant, 'treasurer');
        $catalog = $this->catalogIds($tenant);

        // Treasurer is NOT linked to any denounced unit here — against_unit_id is null.
        $response = $this->tenantRequest($this->token($president, ['create.complaint']), $tenant)
            ->postJson('/api/complaint', [
                'title' => 'Incidencia contra la administración',
                'complaint_category_id' => $catalog['category_id'],
                'complaint_priority_id' => $catalog['priority_id'],
                'against_description' => 'Falta de transparencia en gastos generales',
            ])
            ->assertCreated();

        $complaintId = $response->json('data.id');

        $this->tenantRequest($this->token($treasurer, ['read.complaint']), $tenant)
            ->getJson("/api/complaint/{$complaintId}")
            ->assertOk();

        $recipientIds = $this->latestComplaintCreatedRecipientIds($tenant);

        $this->assertContains($president->id, $recipientIds);
        $this->assertContains($treasurer->id, $recipientIds);
    }

    // =========================================================================
    // Exclusion enforced on write endpoints (review finding C1)
    // =========================================================================

    /**
     * PUT/DELETE previously bypassed visibleTo() (only index/show applied it),
     * so an excluded manager who guessed a complaint's id could still mutate
     * it. buildUpdateFetchQuery/buildDestroyFetchQuery now apply the same scope.
     */
    public function test_excluded_manager_cannot_update_or_destroy_the_complaint(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'president');
        $treasurer = $this->makeUser($tenant, 'treasurer');
        $catalog = $this->catalogIds($tenant);

        $unit = $this->makeUnit($tenant);
        $this->linkToUnit($tenant, $unit, $treasurer);

        $response = $this->tenantRequest($this->token($president, ['create.complaint']), $tenant)
            ->postJson('/api/complaint', [
                'title' => 'Incidencia contra el tesorero',
                'complaint_category_id' => $catalog['category_id'],
                'complaint_priority_id' => $catalog['priority_id'],
                'against_unit_id' => $unit->id,
                'against_description' => 'Uso indebido de fondos comunitarios',
            ])
            ->assertCreated();

        $complaintId = $response->json('data.id');

        // Excluded (linked to the denounced unit, not the reporter) — both writes
        // must be denied, the same as the read scope in the tests above.
        $treasurerToken = $this->token($treasurer, ['update.complaint', 'delete.complaint']);

        $this->tenantRequest($treasurerToken, $tenant)
            ->putJson("/api/complaint/{$complaintId}", ['title' => 'Título alterado'])
            ->assertNotFound();

        $this->tenantRequest($treasurerToken, $tenant)
            ->deleteJson("/api/complaint/{$complaintId}")
            ->assertNotFound();

        // Sanity: a non-excluded manager CAN update/destroy the same complaint.
        $president2Token = $this->token($president, ['update.complaint', 'delete.complaint']);

        $this->tenantRequest($president2Token, $tenant)
            ->putJson("/api/complaint/{$complaintId}", ['title' => 'Título corregido'])
            ->assertOk();

        $this->tenantRequest($president2Token, $tenant)
            ->deleteJson("/api/complaint/{$complaintId}")
            ->assertOk();
    }
}
