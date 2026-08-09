<?php

namespace Modules\AmenityReservations\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\AmenityReservation;
use Modules\AmenityReservations\Models\ReservationMode;
use Modules\AmenityReservations\Support\AmenityReservationsSettings;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Security fix: AmenityReservationController's read endpoints (index/show,
 * search delegates to index) did not scope to the caller's own units — a
 * resident could enumerate every unit's reservations in the same tenant.
 *
 * Mirrors Modules\Collections\Tests\Feature\ChargeController's resident
 * read-scoping tests (finding collections-02 precedent): residents/renters
 * see only their OWN units' reservations, board/admin
 * (`update.amenity_reservation` / `*`) keep full cluster visibility, and a
 * foreign unit's reservation resolves to 404 (not 403) on show.
 */
final class AmenityReservationReadScopingTest extends TestCase
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

    private function tenantWithFlagEnabled(string $name = 'Cluster'): Tenant
    {
        $tenant = $this->makeTenant($name.' '.uniqid());
        $this->seedTenant($tenant);
        $this->inTenant($tenant, function (): void {
            Setting::where('slug', AmenityReservationsSettings::ENABLED)->update(['value' => 'true']);
        });

        return $tenant;
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function residentToken(Tenant $tenant): array
    {
        $user = $this->makeUser($tenant);
        $token = $user->createToken('resident', [
            'read.amenity', 'read.amenity_reservation', 'create.amenity_reservation',
        ])->plainTextToken;

        return [$user, $token];
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function boardToken(Tenant $tenant): array
    {
        $user = $this->makeUser($tenant);
        $token = $user->createToken('board', [
            'read.amenity', 'read.amenity_reservation', 'create.amenity_reservation', 'update.amenity_reservation',
        ])->plainTextToken;

        return [$user, $token];
    }

    /**
     * Pre-existing flakiness fix: UnitFactory's `number` is
     * `optional()->numerify('###')` — ~50% chance of null per call — so two
     * units created in the SAME tenant (same unit_type_id, no tower/floor)
     * can collide on `unit_identity_unique` (all-NULL identity tuple) roughly
     * 1 in 4 times. Force a unique `number` here so tests that create
     * multiple units per tenant (e.g. the board-visibility test below) are
     * deterministic instead of ~25% flaky.
     */
    private function unit(): Unit
    {
        return Unit::factory()->create([
            'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
            'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            'number' => (string) random_int(100000, 999999),
        ]);
    }

    private function attachResident(Unit $unit, User $user): void
    {
        UnitUser::create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'unit_relationship_id' => UnitRelationship::OWNER,
            'is_billing_owner' => true,
        ]);
    }

    private function amenity(array $overrides = []): Amenity
    {
        return Amenity::create(array_merge([
            'name' => 'Amenidad '.uniqid(),
            'capacity' => 10,
            'available_weekdays' => [0, 1, 2, 3, 4, 5, 6],
            'reservation_mode_id' => ReservationMode::BY_DAY,
        ], $overrides));
    }

    private function reservationFor(Unit $unit): AmenityReservation
    {
        return AmenityReservation::factory()->create([
            'amenity_id' => $this->amenity()->id,
            'unit_id' => $unit->id,
        ]);
    }

    // =========================================================================
    // index — resident scoped to own units
    // =========================================================================

    public function test_resident_only_sees_their_own_units_reservations_on_index(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);

        [$ownReservationId, $foreignReservationId] = $this->inTenant($tenant, function () use ($resident): array {
            $ownUnit = $this->unit();
            $this->attachResident($ownUnit, $resident);
            $foreignUnit = $this->unit(); // NOT attached to the resident

            return [
                $this->reservationFor($ownUnit)->id,
                $this->reservationFor($foreignUnit)->id,
            ];
        });

        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_reservation')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($ownReservationId, $ids, 'Resident must see their own unit reservation.');
        $this->assertNotContains($foreignReservationId, $ids, 'Resident must NOT see another unit\'s reservation.');
    }

    // =========================================================================
    // show — 404 on foreign unit, 200 on own
    // =========================================================================

    public function test_resident_gets_404_on_show_of_another_units_reservation_and_200_on_own(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);

        [$ownReservationId, $foreignReservationId] = $this->inTenant($tenant, function () use ($resident): array {
            $ownUnit = $this->unit();
            $this->attachResident($ownUnit, $resident);
            $foreignUnit = $this->unit(); // NOT attached to the resident

            return [
                $this->reservationFor($ownUnit)->id,
                $this->reservationFor($foreignUnit)->id,
            ];
        });

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_reservation/'.$foreignReservationId)
            ->assertStatus(404);

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_reservation/'.$ownReservationId)
            ->assertOk()
            ->assertJsonPath('data.id', $ownReservationId);
    }

    // =========================================================================
    // show — every include the admin detail page requests must be whitelisted
    // =========================================================================

    /**
     * FIX (E2E QA — BUG 1, CRITICAL): AmenityReservationDetailPage requests
     * `include=amenity,amenity.bank_account,unit,slot,reservation_status,
     * surcharges,surcharges.charge,surcharges.charge_concept` — Orion requires
     * every NESTED relation to be individually whitelisted in includes(), and
     * `amenity.bank_account` was missing, so the detail request 422'd with
     * "field is not whitelisted."
     */
    public function test_show_accepts_every_include_the_admin_detail_page_requests(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $token] = $this->boardToken($tenant);

        $reservationId = $this->inTenant($tenant, function (): string {
            $unit = $this->unit();

            return $this->reservationFor($unit)->id;
        });

        $this->tenantRequest($token, $tenant)
            ->getJson(
                '/api/amenity_reservation/'.$reservationId
                .'?include=amenity,amenity.bank_account,unit,slot,reservation_status,surcharges,surcharges.charge,surcharges.charge_concept'
            )
            ->assertOk()
            ->assertJsonPath('data.id', $reservationId);
    }

    // =========================================================================
    // board/admin — full visibility preserved
    // =========================================================================

    public function test_board_member_sees_all_reservations_on_index_and_can_show_any(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $token] = $this->boardToken($tenant);

        [$reservationAId, $reservationBId] = $this->inTenant($tenant, fn (): array => [
            $this->reservationFor($this->unit())->id,
            $this->reservationFor($this->unit())->id,
        ]);

        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_reservation')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($reservationAId, $ids);
        $this->assertContains($reservationBId, $ids);

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_reservation/'.$reservationAId)
            ->assertOk();

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_reservation/'.$reservationBId)
            ->assertOk();
    }

    public function test_super_admin_wildcard_token_sees_all_reservations(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        $user = $this->makeUser($tenant);
        $token = $user->createToken('super', ['*'])->plainTextToken;

        [$reservationAId, $reservationBId] = $this->inTenant($tenant, fn (): array => [
            $this->reservationFor($this->unit())->id,
            $this->reservationFor($this->unit())->id,
        ]);

        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_reservation')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($reservationAId, $ids);
        $this->assertContains($reservationBId, $ids);
    }

    // =========================================================================
    // tenant isolation
    // =========================================================================

    public function test_index_read_scoping_does_not_leak_across_tenants(): void
    {
        $tenantA = $this->tenantWithFlagEnabled('Tenant A');
        $tenantB = $this->tenantWithFlagEnabled('Tenant B');

        $this->inTenant($tenantA, fn () => $this->reservationFor($this->unit()));

        [, $tokenB] = $this->boardToken($tenantB);

        $response = $this->tenantRequest($tokenB, $tenantB)
            ->getJson('/api/amenity_reservation')
            ->assertOk();

        $this->assertCount(0, $response->json('data'), 'Tenant B must not see Tenant A reservations.');
    }

    // =========================================================================
    // Pagination cap — FIX (E2E QA — BUG 2/3): raised from Orion's default 100
    // =========================================================================

    /**
     * AmenityReservationCalendarPage requests `limit: 300` for a visible month
     * range — above Orion's default 100 cap, which 422'd the request and left
     * the calendar silently empty. AmenityReservationController::maxLimit()
     * now returns 500 (a month CAN have more than 100 reservations).
     */
    public function test_search_accepts_the_pagination_limit_the_calendar_requests(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $token] = $this->boardToken($tenant);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation/search', ['limit' => 300])
            ->assertOk();
    }

    public function test_search_still_rejects_a_pagination_limit_above_the_raised_cap(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $token] = $this->boardToken($tenant);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation/search', ['limit' => 501])
            ->assertStatus(422);
    }
}
