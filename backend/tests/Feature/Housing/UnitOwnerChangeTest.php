<?php

declare(strict_types=1);

namespace Tests\Feature\Housing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitUser;
use Modules\Notification\Models\NotificationUser;
use Tests\TestCase;

/**
 * housing-08 — §6.3.5 owner change: ONE transaction closes the active owner
 * association (change_date - 1 day), creates the new owner as titular de pago
 * (change_date), keeps history, notifies previous AND new owner (§6.7), and is
 * restricted to the change_owner.unit ability (Adm-only per §6.2).
 */
final class UnitOwnerChangeTest extends TestCase
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

    public function test_change_owner_closes_previous_creates_new_and_notifies_both(): void
    {
        $tenant = $this->makeTenant('Owner Change');
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);
        $rosa = $this->makeUser($tenant);
        $carlos = $this->makeUser($tenant);
        $unit = $this->makeUnit($tenant);
        $rosaAssociation = $this->attachUnitUser($tenant, $unit, $rosa);
        $token = $this->tokenFor($admin, ['change_owner.unit']);

        $this->tenantRequest($token, $tenant)->postJson('/api/unit/'.$unit->id.'/change-owner', [
            'user_id' => $carlos->id,
            'change_date' => '2026-06-01',
        ])->assertStatus(200)->assertJsonPath('data.user_id', $carlos->id);

        $tenant->run(function () use ($rosaAssociation, $unit, $rosa, $carlos): void {
            $closed = $rosaAssociation->fresh();
            $this->assertSame('2026-05-31', $closed->end_date?->toDateString());

            $newOwner = UnitUser::where('unit_id', $unit->id)
                ->where('user_id', $carlos->id)
                ->whereNull('end_date')
                ->first();
            $this->assertNotNull($newOwner);
            $this->assertSame(UnitRelationship::OWNER, $newOwner->unit_relationship_id);
            $this->assertTrue($newOwner->is_billing_owner);
            $this->assertTrue($newOwner->is_legal_representative);
            $this->assertSame('2026-06-01', $newOwner->start_date?->toDateString());

            // §6.7: previous AND new owner receive the in-app notification record.
            $this->assertTrue(NotificationUser::where('user_id', $rosa->id)->exists());
            $this->assertTrue(NotificationUser::where('user_id', $carlos->id)->exists());
        });
    }

    public function test_change_owner_rejects_current_active_owner(): void
    {
        $tenant = $this->makeTenant('Owner Same');
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);
        $rosa = $this->makeUser($tenant);
        $unit = $this->makeUnit($tenant);
        $this->attachUnitUser($tenant, $unit, $rosa);
        $token = $this->tokenFor($admin, ['change_owner.unit']);

        $this->tenantRequest($token, $tenant)->postJson('/api/unit/'.$unit->id.'/change-owner', [
            'user_id' => $rosa->id,
        ])->assertStatus(422);
    }

    public function test_change_owner_requires_the_dedicated_ability(): void
    {
        $tenant = $this->makeTenant('Owner Authz');
        $this->seedTenant($tenant);
        $treasurerLike = $this->makeUser($tenant);
        $rosa = $this->makeUser($tenant);
        $carlos = $this->makeUser($tenant);
        $unit = $this->makeUnit($tenant);
        $rosaAssociation = $this->attachUnitUser($tenant, $unit, $rosa);

        // unit_user write abilities are NOT enough — §6.2 reserves the owner
        // change for the Administrator (change_owner.unit).
        $token = $this->tokenFor($treasurerLike, ['read.unit', 'create.unit_user', 'update.unit_user']);

        $this->tenantRequest($token, $tenant)->postJson('/api/unit/'.$unit->id.'/change-owner', [
            'user_id' => $carlos->id,
        ])->assertStatus(403);

        $tenant->run(function () use ($rosaAssociation): void {
            $this->assertNull($rosaAssociation->fresh()->end_date);
        });
    }

    public function test_change_owner_with_unknown_user_returns_422_and_keeps_previous_owner(): void
    {
        $tenant = $this->makeTenant('Owner Unknown');
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);
        $rosa = $this->makeUser($tenant);
        $unit = $this->makeUnit($tenant);
        $rosaAssociation = $this->attachUnitUser($tenant, $unit, $rosa);
        $token = $this->tokenFor($admin, ['change_owner.unit']);

        $this->tenantRequest($token, $tenant)->postJson('/api/unit/'.$unit->id.'/change-owner', [
            'user_id' => (string) Str::uuid(),
        ])->assertStatus(422)->assertJsonValidationErrors(['user_id']);

        $tenant->run(function () use ($rosaAssociation): void {
            $this->assertNull($rosaAssociation->fresh()->end_date);
        });
    }
}
