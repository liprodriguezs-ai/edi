<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Models\QrType;
use Modules\Access\Models\VisitorMotive;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\ReservationMode;
use Modules\AmenityReservations\Support\AmenityReservationsSettings;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Setting\Models\Setting;
use Modules\SubscriptionPlan\Database\Seeders\PlanQuotaSeeder;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanQuota;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\QuotaService;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Feature tests for the quota-enforcement capability
 * (openspec/changes/trial-usage-quotas/specs/quota-enforcement/spec.md).
 * Covers REQ-QE-01 through REQ-QE-05 (REQ-QE-06's regression floor is
 * verified by the full suite runs in Phase 11, not here).
 */
final class TrialQuotaEnforcementTest extends TestCase
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

        DB::table('plan_quota')->delete();

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function seedTrialQuota(string $resource, int $limit): void
    {
        PlanQuota::create([
            'billing_package_id' => null,
            'scope' => PlanQuota::SCOPE_TRIAL,
            'resource_key' => $resource,
            'limit_value' => $limit,
        ]);
    }

    /** @return array{0: User, 1: string} */
    private function residentToken(Tenant $tenant): array
    {
        $user = $this->makeUser($tenant);
        $token = $user->createToken('resident', [
            'read.amenity', 'read.amenity_reservation', 'create.amenity_reservation', 'read.visitor', 'create.visitor', '*',
        ])->plainTextToken;

        return [$user, $token];
    }

    private function unitFixture(Tenant $tenant): Unit
    {
        return $this->inTenant($tenant, fn (): Unit => Unit::create([
            'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
            'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            'number' => (string) random_int(100000, 999999),
        ]));
    }

    private function attachResident(Tenant $tenant, Unit $unit, User $user): void
    {
        $this->inTenant($tenant, function () use ($unit, $user): void {
            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
                'is_billing_owner' => true,
            ]);
        });
    }

    private function amenity(Tenant $tenant): Amenity
    {
        return $this->inTenant($tenant, fn (): Amenity => Amenity::create([
            'name' => 'Amenidad '.uniqid(),
            'capacity' => 10,
            'available_weekdays' => [0, 1, 2, 3, 4, 5, 6],
            'reservation_mode_id' => ReservationMode::BY_DAY,
        ]));
    }

    private function enableAmenities(Tenant $tenant): void
    {
        $this->inTenant($tenant, function (): void {
            Setting::where('slug', AmenityReservationsSettings::ENABLED)->update(['value' => 'true']);
        });
    }

    /** Grants a TRIAL-scope entitlement, no band (suggested_unit_max=null). */
    private function grantTrialEntitlement(Tenant $tenant, ?int $unitMax = null): void
    {
        $this->inTenant($tenant, function () use ($unitMax): void {
            $module = BillableModule::create([
                'key' => 'base_'.Str::random(8),
                'name' => 'Base',
                'sort_order' => 0,
                'is_active' => true,
                'is_base' => true,
            ]);
            $plan = BillingPackage::create([
                'key' => 'plan_'.Str::random(8),
                'name' => 'Plan de prueba',
                'sort_order' => 0,
                'is_active' => true,
                'is_public' => true,
                'suggested_unit_min' => $unitMax !== null ? 1 : null,
                'suggested_unit_max' => $unitMax,
            ]);
            TenantEntitlement::create([
                'tenant_id' => tenant('id'),
                'billable_module_id' => $module->id,
                'billing_package_id' => $plan->id,
                'source' => TenantEntitlement::SOURCE_TRIAL,
                'expires_at' => now()->addDays(14),
            ]);
        });
    }

    /** Flips the tenant from trial to active scope: ends the trial entitlement, grants a paid one. */
    private function flipToActive(Tenant $tenant): void
    {
        $this->inTenant($tenant, function (): void {
            TenantEntitlement::where('tenant_id', tenant('id'))->update(['expires_at' => now()->subMinute()]);

            $module = BillableModule::create([
                'key' => 'base_active_'.Str::random(8),
                'name' => 'Base activo',
                'sort_order' => 0,
                'is_active' => true,
                'is_base' => true,
            ]);
            $plan = BillingPackage::create([
                'key' => 'plan_active_'.Str::random(8),
                'name' => 'Plan pagado',
                'sort_order' => 0,
                'is_active' => true,
                'is_public' => true,
            ]);
            TenantEntitlement::create([
                'tenant_id' => tenant('id'),
                'billable_module_id' => $module->id,
                'billing_package_id' => $plan->id,
                'source' => TenantEntitlement::SOURCE_MANUAL,
                'expires_at' => null,
            ]);
        });
    }

    private function motiveId(Tenant $tenant): string
    {
        return $this->inTenant($tenant, fn () => Catalog::id(VisitorMotive::class, 'Social / familiar'));
    }

    // =========================================================================
    // REQ-QE-01 — reaching the limit succeeds; exceeding blocks (all 3 hooks)
    // =========================================================================

    public function test_unit_reaches_the_limit_exactly_then_blocks(): void
    {
        $tenant = $this->makeTenant('Encinos');
        $this->seedTenant($tenant);
        $this->seedTrialQuota(PlanQuota::RESOURCE_UNIT, 1);
        [, $token] = $this->residentToken($tenant);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/unit', [
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'number' => Str::random(6),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ])
            ->assertStatus(201);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/unit', [
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'number' => Str::random(6),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'QUOTA_EXCEEDED');
    }

    public function test_amenity_reservation_reaches_the_limit_exactly_then_blocks(): void
    {
        $tenant = $this->makeTenant('Encinos');
        $this->seedTenant($tenant);
        $this->enableAmenities($tenant);
        $this->seedTrialQuota(PlanQuota::RESOURCE_AMENITY_RESERVATION, 1);
        [$resident, $token] = $this->residentToken($tenant);
        $unit = $this->unitFixture($tenant);
        $this->attachResident($tenant, $unit, $resident);
        $amenity = $this->amenity($tenant);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_date' => now()->addDays(7)->toDateString(),
            ])
            ->assertStatus(201);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_date' => now()->addDays(8)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'QUOTA_EXCEEDED');
    }

    public function test_visitor_reaches_the_limit_exactly_then_blocks(): void
    {
        $tenant = $this->makeTenant('Encinos');
        $this->seedTenant($tenant);
        $this->seedTrialQuota(PlanQuota::RESOURCE_VISITOR, 1);
        [$resident, $token] = $this->residentToken($tenant);
        $unit = $this->unitFixture($tenant);
        $this->attachResident($tenant, $unit, $resident);
        $motiveId = $this->motiveId($tenant);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/visitor', [
                'unit_id' => $unit->id,
                'name' => 'Visita 1',
                'visitor_motive_id' => $motiveId,
                'qr_type_id' => QrType::SINGLE_ENTRY,
            ])
            ->assertStatus(201);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/visitor', [
                'unit_id' => $unit->id,
                'name' => 'Visita 2',
                'visitor_motive_id' => $motiveId,
                'qr_type_id' => QrType::SINGLE_ENTRY,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'QUOTA_EXCEEDED');
    }

    // =========================================================================
    // REQ-QE-02 — scope follows the authoritative subscription state
    // =========================================================================

    public function test_quotas_stop_applying_once_the_tenant_becomes_active(): void
    {
        $tenant = $this->makeTenant('Encinos');
        $this->seedTenant($tenant);
        $this->enableAmenities($tenant);
        $this->grantTrialEntitlement($tenant);
        // Trial-scope limit blocks; NO active-scope row exists at all (fail-open once flipped).
        $this->seedTrialQuota(PlanQuota::RESOURCE_AMENITY_RESERVATION, 0);
        [$resident, $token] = $this->residentToken($tenant);
        $unit = $this->unitFixture($tenant);
        $this->attachResident($tenant, $unit, $resident);
        $amenity = $this->amenity($tenant);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_date' => now()->addDays(7)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'QUOTA_EXCEEDED')
            ->assertJsonPath('scope', 'trial');

        $this->flipToActive($tenant);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_date' => now()->addDays(7)->toDateString(),
            ])
            ->assertStatus(201);
    }

    // =========================================================================
    // REQ-QE-03 — rejection payload is complete
    // =========================================================================

    public function test_rejection_payload_carries_the_full_stable_contract(): void
    {
        $tenant = $this->makeTenant('Encinos');
        $this->seedTenant($tenant);
        $this->grantTrialEntitlement($tenant);
        $this->seedTrialQuota(PlanQuota::RESOURCE_VISITOR, 0);
        [$resident, $token] = $this->residentToken($tenant);
        $unit = $this->unitFixture($tenant);
        $this->attachResident($tenant, $unit, $resident);

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/visitor', [
                'unit_id' => $unit->id,
                'name' => 'Visita',
                'visitor_motive_id' => $this->motiveId($tenant),
                'qr_type_id' => QrType::SINGLE_ENTRY,
            ])
            ->assertStatus(422);

        $response->assertJsonPath('code', 'QUOTA_EXCEEDED')
            ->assertJsonPath('resource', PlanQuota::RESOURCE_VISITOR)
            ->assertJsonPath('limit', 0)
            ->assertJsonPath('used', 0)
            ->assertJsonPath('scope', 'trial')
            ->assertJsonPath('plan_name', 'Plan de prueba');

        $message = (string) $response->json('message');
        $this->assertStringContainsString('visitantes', $message);
        $this->assertStringContainsString('0', $message);
        $this->assertStringNotContainsString(' vos ', ' '.$message.' ', 'Message must use tú-form, never vos');
    }

    // =========================================================================
    // REQ-QE-04 — fail-open: misconfiguration never bricks operations
    // =========================================================================

    public function test_no_resolvable_quota_row_allows(): void
    {
        $tenant = $this->makeTenant('Encinos');
        $this->seedTenant($tenant);
        // NO plan_quota row seeded for `visitor` at all.
        [$resident, $token] = $this->residentToken($tenant);
        $unit = $this->unitFixture($tenant);
        $this->attachResident($tenant, $unit, $resident);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/visitor', [
                'unit_id' => $unit->id,
                'name' => 'Visita',
                'visitor_motive_id' => $this->motiveId($tenant),
                'qr_type_id' => QrType::SINGLE_ENTRY,
            ])
            ->assertStatus(201);
    }

    public function test_no_tenancy_initialized_allows(): void
    {
        $this->seedTrialQuota(PlanQuota::RESOURCE_VISITOR, 0);

        $check = app(QuotaService::class)->checkConsumption(PlanQuota::RESOURCE_VISITOR);

        $this->assertTrue($check->allowed);
        $this->assertNull($check->scope);
    }

    // =========================================================================
    // REQ-QE-05 — safety property: shipped seeds change nothing.
    // Seeds NOTHING but the shipped PlanQuotaSeeder — its OWN test.
    // =========================================================================

    public function test_shipped_seeded_system_is_behaviorally_invisible(): void
    {
        (new PlanQuotaSeeder)->run();

        $tenant = $this->makeTenant('Encinos Seeded');
        $this->seedTenant($tenant);
        $this->enableAmenities($tenant);
        // A tight BAND (5) is the only cap in play — the shipped -1 quota must
        // never additionally interfere with band-only admissions/rejections.
        $this->grantTrialEntitlement($tenant, 5);
        [$resident, $token] = $this->residentToken($tenant);
        $unit = $this->unitFixture($tenant);
        $this->attachResident($tenant, $unit, $resident);
        $amenity = $this->amenity($tenant);
        $motiveId = $this->motiveId($tenant);

        // Units up to and past the band — band keeps rejecting exactly as today.
        for ($i = 0; $i < 4; $i++) {
            $this->tenantRequest($token, $tenant)
                ->postJson('/api/unit', [
                    'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                    'number' => 'seed-'.$i.'-'.Str::random(4),
                    'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
                ])
                ->assertStatus(201);
        }
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/unit', [
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'number' => 'seed-over-'.Str::random(4),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'UNIT_LIMIT_EXCEEDED', 'The band rejection MUST carry its own code, never the quota code');

        // Reservations and visits at volume — never rejected by the shipped -1 quota.
        for ($i = 0; $i < 5; $i++) {
            $this->tenantRequest($token, $tenant)
                ->postJson('/api/amenity_reservation', [
                    'amenity_id' => $amenity->id,
                    'unit_id' => $unit->id,
                    'reservation_date' => now()->addDays(7 + $i)->toDateString(),
                ])
                ->assertStatus(201);

            $this->tenantRequest($token, $tenant)
                ->postJson('/api/visitor', [
                    'unit_id' => $unit->id,
                    'name' => 'Visita seed '.$i,
                    'visitor_motive_id' => $motiveId,
                    'qr_type_id' => QrType::SINGLE_ENTRY,
                ])
                ->assertStatus(201);
        }
    }
}
