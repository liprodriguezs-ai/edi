<?php

declare(strict_types=1);

namespace Tests\Feature\Complaint;

use App\Models\Role as AppRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Complaint\Models\ComplaintCategory;
use Modules\Complaint\Models\ComplaintPriority;
use Modules\Complaint\Models\ComplaintStatus;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Coverage for GET /complaint/{id}/history (spec "History"): a translated
 * change-history timeline read from the existing Laravel Auditing trail.
 *
 *  - Status transitions, assignment and reopen surface as human-readable
 *    diffs (catalog/user names, never raw UUIDs)
 *  - The "created" entry carries no diff (frontend renders a fixed milestone)
 *  - A manager excluded by unit-linkage (§6) cannot read the history
 */
final class ComplaintHistoryTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        // PHPUnit runs in console: owen-it only registers its audit observer at
        // model boot when audit.console is true (default false). Must be set
        // BEFORE the first Complaint/BaseModel usage in this test, or the
        // observer never gets registered and the history endpoint sees zero
        // audits (same gotcha documented in SubscriptionSelfServiceTest /
        // SubscriptionPaymentFlowTest).
        config(['audit.console' => true]);
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

    // =========================================================================
    // Translated timeline
    // =========================================================================

    public function test_history_translates_status_assignment_and_reopen_changes(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'president');
        $secretary = $this->makeUser($tenant, 'secretary');
        $catalog = $this->catalogIds($tenant);
        $token = $this->token($president, ['create.complaint', 'update.complaint', 'read.complaint']);

        $complaintId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/complaint', [
                'title' => 'Fuga de agua en área común',
                'complaint_category_id' => $catalog['category_id'],
                'complaint_priority_id' => $catalog['priority_id'],
                'against_description' => 'Incidencia general del condominio',
            ])
            ->assertCreated()
            ->json('data.id');

        // open -> in_review
        $this->tenantRequest($token, $tenant)
            ->postJson("/api/complaint/{$complaintId}/transition", ['complaint_status_id' => ComplaintStatus::IN_REVIEW])
            ->assertOk();

        // assign
        $this->tenantRequest($token, $tenant)
            ->postJson("/api/complaint/{$complaintId}/assign", ['assigned_user_id' => $secretary->id])
            ->assertOk();

        // in_review -> resolved
        $this->tenantRequest($token, $tenant)
            ->postJson("/api/complaint/{$complaintId}/transition", ['complaint_status_id' => ComplaintStatus::RESOLVED])
            ->assertOk();

        // reopen: resolved -> open
        $this->tenantRequest($token, $tenant)
            ->postJson("/api/complaint/{$complaintId}/transition", ['complaint_status_id' => ComplaintStatus::OPEN])
            ->assertOk();

        $entries = $this->tenantRequest($token, $tenant)
            ->getJson("/api/complaint/{$complaintId}/history")
            ->assertOk()
            ->json('data');

        $this->assertIsArray($entries);
        $this->assertNotEmpty($entries);

        // "created" entry: no diff, author is the reporter, event is machine-readable.
        $createdEntry = collect($entries)->firstWhere('event', 'created');
        $this->assertNotNull($createdEntry, 'Expected a "created" audit entry');
        $this->assertSame([], $createdEntry['changes']);
        $this->assertStringContainsString($president->name, $createdEntry['user_name']);

        // Every "updated" entry's changes must be human-readable (catalog/user
        // names), never raw UUIDs — collect them across all "updated" entries.
        $updatedChanges = collect($entries)
            ->where('event', 'updated')
            ->pluck('changes')
            ->flatten(1);

        $statusChange = $updatedChanges->firstWhere('field', 'complaint_status_id');
        $this->assertNotNull($statusChange, 'Expected at least one translated status change');
        $this->assertSame('Estado', $statusChange['label']);
        $this->assertContains($statusChange['to'], ['En revisión', 'Resuelta', 'Registrada']);
        $this->assertContains($statusChange['from'], ['Registrada', 'En revisión', 'Resuelta']);

        $assignmentChange = $updatedChanges->firstWhere('field', 'assigned_user_id');
        $this->assertNotNull($assignmentChange, 'Expected a translated assignment change');
        $this->assertSame('Asignado a', $assignmentChange['label']);
        $this->assertSame('Sin asignar', $assignmentChange['from']);
        $this->assertStringContainsString($secretary->name, (string) $assignmentChange['to']);

        // No raw UUIDs anywhere in the translated payload.
        $payload = json_encode($entries);
        $this->assertDoesNotMatchRegularExpression(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            (string) preg_replace('/"id":"[0-9a-f-]{36}"/i', '', (string) $payload),
            'History payload must not leak raw UUIDs outside the entry "id" field'
        );
    }

    // =========================================================================
    // Access gate
    // =========================================================================

    public function test_excluded_manager_cannot_read_history(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'president');
        $treasurer = $this->makeUser($tenant, 'treasurer');
        $catalog = $this->catalogIds($tenant);

        $unit = $this->makeUnit($tenant);
        $this->linkToUnit($tenant, $unit, $treasurer);

        $complaintId = $this->tenantRequest($this->token($president, ['create.complaint']), $tenant)
            ->postJson('/api/complaint', [
                'title' => 'Incidencia contra el tesorero',
                'complaint_category_id' => $catalog['category_id'],
                'complaint_priority_id' => $catalog['priority_id'],
                'against_unit_id' => $unit->id,
                'against_description' => 'Uso indebido de fondos comunitarios',
            ])
            ->assertCreated()
            ->json('data.id');

        // Treasurer is linked to the denounced unit and is NOT the reporter — excluded.
        $this->tenantRequest($this->token($treasurer, ['read.complaint']), $tenant)
            ->getJson("/api/complaint/{$complaintId}/history")
            ->assertStatus(403);

        // Sanity: the reporter (not excluded) CAN read it.
        $this->tenantRequest($this->token($president, ['read.complaint']), $tenant)
            ->getJson("/api/complaint/{$complaintId}/history")
            ->assertOk();
    }
}
