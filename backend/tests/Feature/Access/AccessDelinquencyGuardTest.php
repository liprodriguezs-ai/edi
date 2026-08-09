<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\Role as AppRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Access\Models\QrType;
use Modules\Access\Models\Visitor;
use Modules\Access\Models\VisitorMotive;
use Modules\Access\Models\VisitorStatus;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Support\DelinquencySettings;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Delinquency hooks 2-3 (design §5): AccessService::generateQr() (POST
 * /visitor) and VisitorController::approve()/reject() (walk-in). Covers
 * R-DELQ-4 (blocked) and R-DELQ-5 (my-qr/my-log/cancelQr never blocked).
 */
final class AccessDelinquencyGuardTest extends TestCase
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

    private function makeUser(Tenant $tenant, ?string $role = null): User
    {
        $user = User::create([
            'name' => 'Delq '.Str::random(6),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        if ($role !== null) {
            AppRole::firstOrCreate(['name' => $role, 'guard_name' => 'api']);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $user->assignRole($role);
        }

        return $user;
    }

    /** @param array<string> $abilities */
    private function token(User $user, array $abilities): string
    {
        return $user->createToken('test', $abilities)->plainTextToken;
    }

    private function unit(Tenant $tenant): Unit
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

    private function linkToUnit(Unit $unit, User $user): void
    {
        UnitUser::create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'unit_relationship_id' => UnitRelationship::OWNER,
        ]);
    }

    /** Makes $unit OVERDUE with a charge owing $balance minor units. Must run inside tenant scope. */
    private function makeUnitOverdue(Unit $unit, int $balance): void
    {
        $unit->charge_status_id = ChargeStatus::OVERDUE;
        $unit->save();

        $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));
        $concept = ChargeConcept::create([
            'code' => 'ACDLQ_'.strtoupper(substr(md5(uniqid('', true)), 0, 6)),
            'name' => 'Access delinquency guard test concept',
            'charge_concept_category_id' => $category->id,
            'is_active' => true,
        ]);

        Charge::create([
            'charge_concept_id' => $concept->id,
            'unit_id' => $unit->id,
            'expected_amount' => $balance,
            'paid_amount' => 0,
            'issued_at' => '2026-01-01',
            'due_date' => '2026-01-10',
            'charge_status_id' => ChargeStatus::OVERDUE,
            'is_cancelled' => false,
        ]);
    }

    private function enableVisitorAccessRestrictions(int $minOverdueAmount = 0): void
    {
        Setting::updateOrCreate(['slug' => DelinquencySettings::RESTRICTIONS_ENABLED], ['name' => DelinquencySettings::RESTRICTIONS_ENABLED, 'slug' => DelinquencySettings::RESTRICTIONS_ENABLED, 'value' => true, 'type' => Setting::TYPE_BOOLEAN]);
        Setting::updateOrCreate(['slug' => DelinquencySettings::MIN_OVERDUE_AMOUNT], ['name' => DelinquencySettings::MIN_OVERDUE_AMOUNT, 'slug' => DelinquencySettings::MIN_OVERDUE_AMOUNT, 'value' => $minOverdueAmount, 'type' => Setting::TYPE_INTEGER]);
        Setting::updateOrCreate(['slug' => DelinquencySettings::BLOCK_VISITOR_ACCESS], ['name' => DelinquencySettings::BLOCK_VISITOR_ACCESS, 'slug' => DelinquencySettings::BLOCK_VISITOR_ACCESS, 'value' => true, 'type' => Setting::TYPE_BOOLEAN]);
    }

    // =========================================================================
    // Hook 2 — generateQr (POST /visitor)
    // =========================================================================

    public function test_delinquent_unit_is_blocked_from_generating_a_visitor_qr(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $resident = $this->makeUser($tenant);
        $unit = $this->unit($tenant);

        $tenant->run(function () use ($unit, $resident): void {
            $this->linkToUnit($unit, $resident);
            $this->makeUnitOverdue($unit, 750_000);
            $this->enableVisitorAccessRestrictions(500_000);
        });

        $response = $this->tenantRequest($this->token($resident, ['read.visitor', 'create.visitor']), $tenant)
            ->postJson('/api/visitor', [
                'unit_id' => $unit->id,
                'name' => 'Visita Bloqueada',
                'visitor_motive_id' => Catalog::id(VisitorMotive::class, 'Social / familiar'),
                'qr_type_id' => QrType::SINGLE_ENTRY,
            ])
            ->assertStatus(403);

        $response->assertJsonPath('code', 'DELINQUENT_RESTRICTED');
        $response->assertJsonPath('overdue_balance.amount', '750000');
    }

    // =========================================================================
    // Hook 3 — approve/reject
    // =========================================================================

    public function test_delinquent_unit_is_blocked_from_approving_or_rejecting_a_walk_in(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $resident = $this->makeUser($tenant);
        $guard = $this->makeUser($tenant, 'security');
        $unit = $this->unit($tenant);

        $tenant->run(function () use ($unit, $resident): void {
            $this->linkToUnit($unit, $resident);
        });

        $visitorId = $this->tenantRequest($this->token($guard, ['create.access_log']), $tenant)
            ->postJson('/api/access/entry-request', [
                'unit_id' => $unit->id,
                'name' => 'Visita en Caseta',
                'visitor_motive_id' => Catalog::id(VisitorMotive::class, 'Social / familiar'),
            ])
            ->assertStatus(201)
            ->json('data.id');

        $tenant->run(function () use ($unit): void {
            $this->makeUnitOverdue($unit, 750_000);
            $this->enableVisitorAccessRestrictions(500_000);
        });

        $approveResponse = $this->tenantRequest($this->token($resident, []), $tenant)
            ->postJson("/api/visitor/{$visitorId}/approve")
            ->assertStatus(403);
        $approveResponse->assertJsonPath('code', 'DELINQUENT_RESTRICTED');

        $this->tenantRequest($this->token($resident, []), $tenant)
            ->postJson("/api/visitor/{$visitorId}/reject")
            ->assertStatus(403)
            ->assertJsonPath('code', 'DELINQUENT_RESTRICTED');

        $tenant->run(function () use ($visitorId): void {
            $this->assertSame(VisitorStatus::PENDING_APPROVAL, Visitor::findOrFail($visitorId)->visitor_status_id);
        });
    }

    // =========================================================================
    // R-DELQ-5 — never blocked
    // =========================================================================

    public function test_my_qr_my_log_and_cancel_are_unaffected_for_a_delinquent_unit(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $resident = $this->makeUser($tenant);
        $unit = $this->unit($tenant);

        $tenant->run(function () use ($unit, $resident): void {
            $this->linkToUnit($unit, $resident);
        });

        // Generate the QR BEFORE the unit goes delinquent (hook 2 would otherwise block it).
        $visitorId = $this->tenantRequest($this->token($resident, ['read.visitor', 'create.visitor']), $tenant)
            ->postJson('/api/visitor', [
                'unit_id' => $unit->id,
                'name' => 'Visita Previa',
                'visitor_motive_id' => Catalog::id(VisitorMotive::class, 'Social / familiar'),
                'qr_type_id' => QrType::SINGLE_ENTRY,
            ])
            ->assertStatus(201)
            ->json('data.id');

        $tenant->run(function () use ($unit): void {
            $this->makeUnitOverdue($unit, 750_000);
            $this->enableVisitorAccessRestrictions(500_000);
        });

        $this->tenantRequest($this->token($resident, []), $tenant)
            ->getJson('/api/access/my-qr')
            ->assertOk();

        $this->tenantRequest($this->token($resident, []), $tenant)
            ->getJson('/api/access/my-log')
            ->assertOk();

        $this->tenantRequest($this->token($resident, []), $tenant)
            ->postJson("/api/visitor/{$visitorId}/cancel")
            ->assertOk();
    }
}
