<?php

declare(strict_types=1);

namespace Modules\Housing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\Tenant\Models\Tenant;
use Tests\Feature\Housing\HousingTestHelpers;
use Tests\Support\Catalog;
use Tests\TestCase;

/**
 * Feature tests for unit-limit-enforcement (REQ-01 – REQ-04, REQ-06 – REQ-09).
 *
 * Covers all 4 HTTP increase paths on a seeded Básico band (suggested_unit_max
 * = 25): POST /unit, POST /unit/batch, POST /unit/{id}/restore,
 * POST /unit/batch/restore.
 */
final class UnitLimitEnforcementTest extends TestCase
{
    use HousingTestHelpers;
    use RefreshDatabase;

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
    // Fixture helpers
    // =========================================================================

    /**
     * Seed a Básico-style band (suggested_unit_max = $limit) as the tenant's
     * ONLY active entitlement.
     */
    private function grantBand(Tenant $tenant, string $planName, int $limit): BillingPackage
    {
        return $tenant->run(function () use ($tenant, $planName, $limit): BillingPackage {
            $module = BillableModule::create([
                'key' => 'base_'.Str::random(6),
                'name' => 'Plan Base',
                'sort_order' => 0,
                'is_active' => true,
                'is_base' => true,
            ]);

            $plan = BillingPackage::create([
                'key' => Str::slug($planName).'_'.Str::random(6),
                'name' => $planName,
                'sort_order' => 0,
                'is_active' => true,
                'is_public' => true,
                'suggested_unit_min' => 1,
                'suggested_unit_max' => $limit,
            ]);

            TenantEntitlement::create([
                'tenant_id' => $tenant->id,
                'billable_module_id' => $module->id,
                'billing_package_id' => $plan->id,
                'source' => TenantEntitlement::SOURCE_MANUAL,
                'expires_at' => null,
            ]);

            return $plan;
        });
    }

    /**
     * Create $count active units WITHOUT going through HTTP (used to seed the
     * fixture's baseline count before the guard is exercised).
     */
    private function seedActiveUnits(Tenant $tenant, int $count): void
    {
        $tenant->run(function () use ($tenant, $count): void {
            $unitTypeId = Catalog::id(UnitType::class, 'Casa');
            $unitStatusId = Catalog::id(UnitStatus::class, 'Activa');

            for ($i = 0; $i < $count; $i++) {
                // withoutEvents() mutes BelongsToTenant's own `creating` listener
                // too (a normal event listener, not a query scope) — forceFill()
                // bypasses the $fillable guard so tenant_id still gets set directly.
                Unit::withoutEvents(function () use ($tenant, $i, $unitTypeId, $unitStatusId): void {
                    $unit = new Unit;
                    $unit->forceFill([
                        'tenant_id' => $tenant->id,
                        'unit_type_id' => $unitTypeId,
                        'unit_status_id' => $unitStatusId,
                        'number' => 'seed-'.$i.'-'.Str::random(4),
                    ]);
                    $unit->save();
                });
            }
        });
    }

    /**
     * Seed an already-soft-deleted unit WITHOUT going through the guard
     * (bypasses `creating()`) so fixture setup at/near the cap never trips
     * over its own guard before the test's HTTP call runs.
     */
    private function seedTrashedUnit(Tenant $tenant, string $number): Unit
    {
        return $tenant->run(function () use ($tenant, $number): Unit {
            $unitTypeId = Catalog::id(UnitType::class, 'Casa');
            $unitStatusId = Catalog::id(UnitStatus::class, 'Activa');

            /** @var Unit $unit */
            $unit = Unit::withoutEvents(function () use ($tenant, $number, $unitTypeId, $unitStatusId): Unit {
                $unit = new Unit;
                $unit->forceFill([
                    'tenant_id' => $tenant->id,
                    'unit_type_id' => $unitTypeId,
                    'unit_status_id' => $unitStatusId,
                    'number' => $number,
                ]);
                $unit->save();

                return $unit;
            });

            $unit->delete();

            return $unit;
        });
    }

    private function unitPayload(?string $number = null): array
    {
        return [
            'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
            'number' => $number ?? Str::random(8),
            'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
        ];
    }

    // =========================================================================
    // REQ-01 — POST /unit
    // =========================================================================

    public function test_single_create_at_the_limit_succeeds(): void
    {
        $tenant = $this->makeTenant('Encinos Cap');
        $this->seedTenant($tenant);
        $this->grantBand($tenant, 'Básico', 25);
        $this->seedActiveUnits($tenant, 24);
        $admin = $this->makeUser($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/unit', $this->unitPayload())
            ->assertStatus(201);

        $tenant->run(function (): void {
            $this->assertSame(25, Unit::count());
        });
    }

    public function test_single_create_over_the_limit_is_rejected_with_tu_form_message(): void
    {
        $tenant = $this->makeTenant('Encinos Over');
        $this->seedTenant($tenant);
        $this->grantBand($tenant, 'Básico', 25);
        $this->seedActiveUnits($tenant, 25);
        $admin = $this->makeUser($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/unit', $this->unitPayload())
            ->assertStatus(422);

        $response->assertJsonPath('code', 'UNIT_LIMIT_EXCEEDED')
            ->assertJsonPath('plan_name', 'Básico')
            ->assertJsonPath('unit_limit', 25)
            ->assertJsonPath('active_units', 25)
            ->assertJsonPath('attempted_increase', 1);

        $message = $response->json('message');
        $this->assertStringContainsString('Tu plan', $message);
        $this->assertStringNotContainsString(' vos ', ' '.$message.' ', 'Message must use tú-form, never vos');

        $tenant->run(function (): void {
            $this->assertSame(25, Unit::count(), 'Active unit count must remain unchanged after a rejected create');
        });
    }

    // =========================================================================
    // REQ-02 — POST /unit/batch (atomic)
    // =========================================================================

    public function test_batch_over_the_limit_admits_zero_units(): void
    {
        $tenant = $this->makeTenant('Encinos Batch Over');
        $this->seedTenant($tenant);
        $this->grantBand($tenant, 'Básico', 25);
        $this->seedActiveUnits($tenant, 20);
        $admin = $this->makeUser($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $resources = [];
        for ($i = 0; $i < 10; $i++) {
            $resources[] = $this->unitPayload('batch-over-'.$i);
        }

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/unit/batch', ['resources' => $resources])
            ->assertStatus(422)
            ->assertJsonPath('code', 'UNIT_LIMIT_EXCEEDED')
            ->assertJsonPath('attempted_increase', 10);

        $tenant->run(function (): void {
            $this->assertSame(20, Unit::count(), 'The batch must be all-or-nothing — not partially admitted');
        });
    }

    public function test_batch_within_the_remaining_room_succeeds_in_full(): void
    {
        $tenant = $this->makeTenant('Encinos Batch Fits');
        $this->seedTenant($tenant);
        $this->grantBand($tenant, 'Básico', 25);
        $this->seedActiveUnits($tenant, 20);
        $admin = $this->makeUser($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $resources = [];
        for ($i = 0; $i < 5; $i++) {
            $resources[] = $this->unitPayload('batch-fit-'.$i);
        }

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/unit/batch', ['resources' => $resources])
            ->assertStatus(200);

        $tenant->run(function (): void {
            $this->assertSame(25, Unit::count());
        });
    }

    /**
     * Batch atomicity is REAL but config-dependent: HandlesTransactions::startTransaction()
     * is a no-op unless `orion.transactions.enabled` is true (the vendor default is
     * FALSE — it only works here because config/orion.php explicitly enables it). If
     * that config ever flips, all-or-nothing silently breaks with NO test failure
     * elsewhere — this test pins BOTH the config value and the observable behavior.
     *
     * The unit-limit pre-flight allows this batch (it fits the band); the mid-batch
     * failure is triggered by the PRE-EXISTING duplicate-identity guard
     * (UnitController::performStore -> assertUnitIdentityIsUnique) so this test proves
     * general Orion batch atomicity, which unit-limit-enforcement's Decision 2 relies on.
     */
    public function test_batch_atomicity_depends_on_orion_transactions_being_enabled(): void
    {
        $this->assertTrue(
            config('orion.transactions.enabled'),
            'orion.transactions.enabled must stay true — batch all-or-nothing silently breaks otherwise'
        );

        $tenant = $this->makeTenant('Encinos Batch Atomic');
        $this->seedTenant($tenant);
        $this->grantBand($tenant, 'Básico', 25);
        $admin = $this->makeUser($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $duplicateNumber = 'dup-'.Str::random(6);

        $resources = [
            $this->unitPayload($duplicateNumber),
            $this->unitPayload('unique-'.Str::random(6)),
            // Row 3 collides with row 1's identity — fails mid-batch, well within the band.
            $this->unitPayload($duplicateNumber),
        ];

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/unit/batch', ['resources' => $resources])
            ->assertStatus(422);

        $tenant->run(function (): void {
            $this->assertSame(0, Unit::count(), 'A mid-batch failure must roll back EVERY row in the batch, not just the failing one');
        });
    }

    // =========================================================================
    // REQ-03 — POST /unit/{id}/restore
    // =========================================================================

    public function test_restore_at_the_limit_is_rejected(): void
    {
        $tenant = $this->makeTenant('Encinos Restore');
        $this->seedTenant($tenant);
        $this->grantBand($tenant, 'Básico', 25);
        $this->seedActiveUnits($tenant, 25);
        $admin = $this->makeUser($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $trashedUnit = $this->seedTrashedUnit($tenant, 'trashed-'.Str::random(6));

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/unit/'.$trashedUnit->id.'/restore')
            ->assertStatus(422)
            ->assertJsonPath('code', 'UNIT_LIMIT_EXCEEDED');

        $tenant->run(function () use ($trashedUnit): void {
            $this->assertNotNull(Unit::onlyTrashed()->find($trashedUnit->id), 'The unit must remain soft-deleted');
            $this->assertSame(25, Unit::count());
        });
    }

    // =========================================================================
    // REQ-04 — POST /unit/batch/restore (atomic)
    // =========================================================================

    public function test_batch_restore_over_the_limit_restores_zero_units(): void
    {
        $tenant = $this->makeTenant('Encinos Batch Restore Over');
        $this->seedTenant($tenant);
        $this->grantBand($tenant, 'Básico', 25);
        $this->seedActiveUnits($tenant, 20);
        $admin = $this->makeUser($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $trashedIds = [];
        for ($i = 0; $i < 8; $i++) {
            $trashedIds[] = $this->seedTrashedUnit($tenant, 'batch-trash-'.$i)->id;
        }

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/unit/batch/restore', ['resources' => $trashedIds])
            ->assertStatus(422)
            ->assertJsonPath('attempted_increase', 8);

        $tenant->run(function () use ($trashedIds): void {
            $this->assertSame(20, Unit::count());
            $this->assertSame(8, Unit::onlyTrashed()->whereIn('id', $trashedIds)->count(), 'All 8 must remain soft-deleted');
        });
    }

    /**
     * Pins the `restoring()` skip: `buildBatchRestoreFetchQuery()` uses
     * `withTrashed()` (not `onlyTrashed()`), so a caller mixing a LIVE unit id
     * with trashed ones must only be charged for the trashed ones.
     */
    public function test_batch_restore_mixing_a_live_id_with_trashed_ids_only_charges_the_trashed_ones(): void
    {
        $tenant = $this->makeTenant('Encinos Mixed Restore');
        $this->seedTenant($tenant);
        // Room for exactly 4 more: 1 live (no-op) + 3 trashed restores.
        $this->grantBand($tenant, 'Básico', 24);
        $this->seedActiveUnits($tenant, 20);
        $admin = $this->makeUser($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $liveUnit = $this->makeUnit($tenant, ['number' => 'live-'.Str::random(6)]);

        $trashedIds = [];
        for ($i = 0; $i < 3; $i++) {
            $trashedIds[] = $this->seedTrashedUnit($tenant, 'mixed-trash-'.$i)->id;
        }

        $resources = array_merge([$liveUnit->id], $trashedIds);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/unit/batch/restore', ['resources' => $resources])
            ->assertStatus(200);

        $tenant->run(function () use ($trashedIds): void {
            // 20 baseline + liveUnit (already active, +1) + 3 restored = 24 (exactly the cap).
            $this->assertSame(24, Unit::count());
            $this->assertSame(0, Unit::onlyTrashed()->whereIn('id', $trashedIds)->count(), 'All 3 formerly-trashed units must now be restored');
        });
    }

    // =========================================================================
    // REQ-06 — soft-deleted units never consume the band
    // =========================================================================

    public function test_deleting_a_unit_frees_a_slot(): void
    {
        $tenant = $this->makeTenant('Encinos Free Slot');
        $this->seedTenant($tenant);
        $this->grantBand($tenant, 'Básico', 25);
        $this->seedActiveUnits($tenant, 25);
        $admin = $this->makeUser($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $unitToDelete = $tenant->run(fn () => Unit::first());

        $this->tenantRequest($token, $tenant)
            ->deleteJson('/api/unit/'.$unitToDelete->id)
            ->assertStatus(200);

        $tenant->run(function (): void {
            $this->assertSame(24, Unit::count());
        });

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/unit', $this->unitPayload())
            ->assertStatus(201);

        $tenant->run(function (): void {
            $this->assertSame(25, Unit::count());
        });
    }

    // =========================================================================
    // REQ-07 — in-place updates are never blocked
    // =========================================================================

    public function test_updating_an_active_unit_at_the_limit_succeeds(): void
    {
        $tenant = $this->makeTenant('Encinos Update');
        $this->seedTenant($tenant);
        $this->grantBand($tenant, 'Básico', 25);
        $this->seedActiveUnits($tenant, 25);
        $admin = $this->makeUser($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $existingUnit = $tenant->run(fn () => Unit::first());

        $this->tenantRequest($token, $tenant)
            ->patchJson('/api/unit/'.$existingUnit->id, ['notes' => 'renamed'])
            ->assertStatus(200);

        $tenant->run(function (): void {
            $this->assertSame(25, Unit::count());
        });
    }

    // =========================================================================
    // Fail-open without an active entitlement
    // =========================================================================

    public function test_fails_open_when_the_tenant_has_no_active_entitlement(): void
    {
        $tenant = $this->makeTenant('Encinos No Entitlement');
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/unit', $this->unitPayload())
            ->assertStatus(201);
    }

    // =========================================================================
    // REQ-08 — top-band copy degrades gracefully
    // =========================================================================

    public function test_premium_tenant_at_the_ceiling_gets_no_upgrade_link(): void
    {
        $tenant = $this->makeTenant('Encinos Premium');
        $this->seedTenant($tenant);
        $this->grantBand($tenant, 'Premium', 300);
        $this->seedActiveUnits($tenant, 300);
        $admin = $this->makeUser($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/unit', $this->unitPayload())
            ->assertStatus(422)
            ->assertJsonPath('plan_name', 'Premium')
            ->assertJsonPath('unit_limit', 300)
            ->assertJsonPath('suggested_plan_name', null);

        $this->assertStringNotContainsString('Considera cambiar', (string) $response->json('message'));
    }

    // =========================================================================
    // REQ-09 — batch rejection message names all four values
    // =========================================================================

    public function test_batch_rejection_message_names_all_four_values(): void
    {
        $tenant = $this->makeTenant('Encinos Inicial');
        $this->seedTenant($tenant);
        $this->grantBand($tenant, 'Inicial', 50);
        $this->seedActiveUnits($tenant, 48);
        $admin = $this->makeUser($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $resources = [];
        for ($i = 0; $i < 5; $i++) {
            $resources[] = $this->unitPayload('inicial-'.$i);
        }

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/unit/batch', ['resources' => $resources])
            ->assertStatus(422)
            ->assertJsonPath('plan_name', 'Inicial')
            ->assertJsonPath('unit_limit', 50)
            ->assertJsonPath('active_units', 48)
            ->assertJsonPath('attempted_increase', 5);

        $message = (string) $response->json('message');
        $this->assertStringContainsString('Inicial', $message);
        $this->assertStringContainsString('50', $message);
        $this->assertStringContainsString('48', $message);
        $this->assertStringContainsString('5', $message);
    }
}
