<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Models\QrType;
use Modules\Access\Models\Visitor;
use Modules\Access\Models\VisitorMotive;
use Modules\Access\Services\AccessService;
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
use Modules\SubscriptionPlan\Exceptions\QuotaExceededException;
use Modules\SubscriptionPlan\Models\PlanQuota;
use Modules\SubscriptionPlan\Models\TenantQuotaUsage;
use Modules\SubscriptionPlan\Services\QuotaService;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Feature tests for the quota-consumption-tracking capability
 * (openspec/changes/trial-usage-quotas/specs/quota-consumption-tracking/spec.md).
 * Covers REQ-CT-01 through REQ-CT-05 for `amenity_reservation` (via
 * ReservationService::create) and `visitor` (via AccessService::generateQr).
 * Every test seeds its OWN quota (never relies on the shipped `-1`).
 */
final class TrialQuotaConsumptionTest extends TestCase
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

    private function tenantWithAmenitiesEnabled(): Tenant
    {
        $tenant = $this->makeTenant('Cluster '.Str::random(5));
        $this->seedTenant($tenant);
        $this->inTenant($tenant, function (): void {
            Setting::where('slug', AmenityReservationsSettings::ENABLED)->update(['value' => 'true']);
        });

        return $tenant;
    }

    private function seedTrialQuota(string $resource, int $limit): void
    {
        PlanQuota::create([
            'billing_package_id' => null,
            'scope' => PlanQuota::SCOPE_TRIAL,
            'resource_key' => $resource,
            'limit_value' => $limit,
        ]);
    }

    private function usedCount(Tenant $tenant, string $resource): int
    {
        return $this->inTenant($tenant, fn (): int => (int) (TenantQuotaUsage::where('resource_key', $resource)->value('used_count') ?? 0));
    }

    /** @return array{0: User, 1: string} */
    private function residentToken(Tenant $tenant, array $extraAbilities = []): array
    {
        $user = $this->makeUser($tenant);
        $token = $user->createToken('resident', array_merge(
            ['read.amenity', 'read.amenity_reservation', 'create.amenity_reservation', 'read.visitor', 'create.visitor'],
            $extraAbilities
        ))->plainTextToken;

        return [$user, $token];
    }

    private function unit(Tenant $tenant): Unit
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

    private function futureDate(int $daysFromNow = 7): string
    {
        return now()->addDays($daysFromNow)->toDateString();
    }

    // =========================================================================
    // REQ-CT-01 — creating a reservation increments the counter by exactly 1
    // =========================================================================

    public function test_creating_a_reservation_increments_the_counter_by_one(): void
    {
        $tenant = $this->tenantWithAmenitiesEnabled();
        $this->seedTrialQuota(PlanQuota::RESOURCE_AMENITY_RESERVATION, 5);
        [$resident, $token] = $this->residentToken($tenant);
        $unit = $this->unit($tenant);
        $this->attachResident($tenant, $unit, $resident);
        $amenity = $this->amenity($tenant);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_date' => $this->futureDate(),
            ])
            ->assertStatus(201);

        $this->assertSame(1, $this->usedCount($tenant, PlanQuota::RESOURCE_AMENITY_RESERVATION));
    }

    // =========================================================================
    // REQ-CT-02 — a rolled-back transaction consumes ZERO quota.
    // The highest-risk correctness property — its OWN isolated test.
    // =========================================================================

    public function test_a_rolled_back_reservation_transaction_consumes_zero_quota(): void
    {
        $tenant = $this->tenantWithAmenitiesEnabled();
        // Room for both attempts — proves the SECOND rejection is the by_day
        // uniqueness abort, never the quota guard.
        $this->seedTrialQuota(PlanQuota::RESOURCE_AMENITY_RESERVATION, 5);
        [$resident, $token] = $this->residentToken($tenant);
        $unit = $this->unit($tenant);
        $this->attachResident($tenant, $unit, $resident);
        $amenity = $this->amenity($tenant);
        $date = $this->futureDate();

        // First reservation succeeds — consumes 1.
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_date' => $date,
            ])
            ->assertStatus(201);

        $this->assertSame(1, $this->usedCount($tenant, PlanQuota::RESOURCE_AMENITY_RESERVATION));

        // Second attempt for the SAME (amenity, date) passes the quota check
        // (room remains) but its transaction rolls back on the by_day unique
        // constraint (ReservationService::create :151-155, abort 409) — the
        // increment MUST unwind with it.
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_date' => $date,
            ])
            ->assertStatus(409);

        $this->assertSame(
            1,
            $this->inTenant($tenant, fn (): int => AmenityReservation::count()),
            'The rolled-back attempt must not have persisted a reservation'
        );
        $this->assertSame(
            1,
            $this->usedCount($tenant, PlanQuota::RESOURCE_AMENITY_RESERVATION),
            'The rolled-back transaction MUST leave the counter unchanged — this is the property this test exists to pin'
        );
    }

    // =========================================================================
    // REQ-CT-03 — deletion never refunds consumption (amenity_reservation)
    // =========================================================================

    public function test_deleting_a_reservation_does_not_refund_quota(): void
    {
        $tenant = $this->tenantWithAmenitiesEnabled();
        $this->seedTrialQuota(PlanQuota::RESOURCE_AMENITY_RESERVATION, 1);
        [$resident, $token] = $this->residentToken($tenant);
        $unit = $this->unit($tenant);
        $this->attachResident($tenant, $unit, $resident);
        $amenity = $this->amenity($tenant);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_date' => $this->futureDate(),
            ])
            ->assertStatus(201);

        $this->inTenant($tenant, function (): void {
            AmenityReservation::first()->delete();
        });

        $this->assertSame(1, $this->usedCount($tenant, PlanQuota::RESOURCE_AMENITY_RESERVATION), 'Deleting must NOT refund the counter');

        // At the limit (1) — the next attempt must still be blocked.
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_date' => $this->futureDate(8),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'QUOTA_EXCEEDED');
    }

    // =========================================================================
    // REQ-CT-04 — visit registration consumes 1; physical entries and
    // guard-initiated entries consume nothing
    // =========================================================================

    public function test_visit_registration_consumes_one_visitor_quota(): void
    {
        $tenant = $this->makeTenant('Cluster '.Str::random(5));
        $this->seedTenant($tenant);
        $this->seedTrialQuota(PlanQuota::RESOURCE_VISITOR, 5);
        [$resident, $token] = $this->residentToken($tenant);
        $unit = $this->unit($tenant);
        $this->attachResident($tenant, $unit, $resident);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/visitor', [
                'unit_id' => $unit->id,
                'name' => 'Visita '.Str::random(5),
                'visitor_motive_id' => $this->inTenant($tenant, fn () => Catalog::id(VisitorMotive::class, 'Social / familiar')),
                'qr_type_id' => QrType::SINGLE_ENTRY,
            ])
            ->assertStatus(201);

        $this->assertSame(1, $this->usedCount($tenant, PlanQuota::RESOURCE_VISITOR));
    }

    public function test_physical_entries_and_exits_after_the_qr_cost_nothing(): void
    {
        $tenant = $this->makeTenant('Cluster '.Str::random(5));
        $this->seedTenant($tenant);
        $this->seedTrialQuota(PlanQuota::RESOURCE_VISITOR, 5);
        [$resident] = $this->residentToken($tenant);
        $unit = $this->unit($tenant);
        $this->attachResident($tenant, $unit, $resident);

        $visitor = $this->inTenant($tenant, fn (): Visitor => app(AccessService::class)->generateQr([
            'unit_id' => $unit->id,
            'name' => 'Visita '.Str::random(5),
            'visitor_motive_id' => Catalog::id(VisitorMotive::class, 'Social / familiar'),
            'qr_type_id' => QrType::SINGLE_ENTRY,
        ]));

        $this->assertSame(1, $this->usedCount($tenant, PlanQuota::RESOURCE_VISITOR));

        // Three scans (entry/exit cycles) of the SAME QR — none of these are
        // resident-initiated registration, so none may consume quota.
        for ($i = 0; $i < 3; $i++) {
            $this->inTenant($tenant, function () use ($visitor): void {
                app(AccessService::class)->scanQr($visitor->qr_token);
            });
        }

        $this->assertSame(1, $this->usedCount($tenant, PlanQuota::RESOURCE_VISITOR), 'Physical entries/exits must cost nothing');
    }

    public function test_guard_initiated_entry_request_consumes_nothing_even_at_the_limit(): void
    {
        $tenant = $this->makeTenant('Cluster '.Str::random(5));
        $this->seedTenant($tenant);
        $this->seedTrialQuota(PlanQuota::RESOURCE_VISITOR, 0);
        $unit = $this->unit($tenant);
        $guard = $this->makeUser($tenant);
        $token = $guard->createToken('guard', ['create.access_log'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/access/entry-request', [
                'unit_id' => $unit->id,
                'name' => 'Visita en caseta '.Str::random(5),
                'visitor_motive_id' => $this->inTenant($tenant, fn () => Catalog::id(VisitorMotive::class, 'Social / familiar')),
            ])
            ->assertStatus(201);

        $this->assertSame(0, $this->usedCount($tenant, PlanQuota::RESOURCE_VISITOR), 'Guard-initiated entryRequest must never consume visitor quota');
    }

    public function test_deleting_a_visit_does_not_free_visitor_quota(): void
    {
        $tenant = $this->makeTenant('Cluster '.Str::random(5));
        $this->seedTenant($tenant);
        $this->seedTrialQuota(PlanQuota::RESOURCE_VISITOR, 1);
        [$resident, $token] = $this->residentToken($tenant);
        $unit = $this->unit($tenant);
        $this->attachResident($tenant, $unit, $resident);

        $visitor = $this->inTenant($tenant, fn (): Visitor => app(AccessService::class)->generateQr([
            'unit_id' => $unit->id,
            'name' => 'Visita '.Str::random(5),
            'visitor_motive_id' => Catalog::id(VisitorMotive::class, 'Social / familiar'),
            'qr_type_id' => QrType::SINGLE_ENTRY,
        ]));

        $this->inTenant($tenant, function () use ($visitor): void {
            $visitor->delete();
        });

        $this->assertSame(1, $this->usedCount($tenant, PlanQuota::RESOURCE_VISITOR), 'Deleting a visit must NOT free quota room');

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/visitor', [
                'unit_id' => $unit->id,
                'name' => 'Otra visita',
                'visitor_motive_id' => $this->inTenant($tenant, fn () => Catalog::id(VisitorMotive::class, 'Social / familiar')),
                'qr_type_id' => QrType::SINGLE_ENTRY,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'QUOTA_EXCEEDED');
    }

    // =========================================================================
    // REQ-CT-05 — sequential check-then-increment correctness at the boundary
    // =========================================================================

    public function test_sequential_consumption_at_the_boundary_never_exceeds_the_quota(): void
    {
        $tenant = $this->makeTenant('Cluster '.Str::random(5));
        $this->seedTenant($tenant);
        $this->seedTrialQuota(PlanQuota::RESOURCE_VISITOR, 3);
        [$resident, $token] = $this->residentToken($tenant);
        $unit = $this->unit($tenant);
        $this->attachResident($tenant, $unit, $resident);
        $motiveId = $this->inTenant($tenant, fn () => Catalog::id(VisitorMotive::class, 'Social / familiar'));

        // 1st and 2nd succeed (used=2).
        for ($i = 0; $i < 2; $i++) {
            $this->tenantRequest($token, $tenant)
                ->postJson('/api/visitor', [
                    'unit_id' => $unit->id,
                    'name' => 'Visita '.$i,
                    'visitor_motive_id' => $motiveId,
                    'qr_type_id' => QrType::SINGLE_ENTRY,
                ])
                ->assertStatus(201);
        }

        // 3rd hits the limit exactly — succeeds (used=3).
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/visitor', [
                'unit_id' => $unit->id,
                'name' => 'Visita 3',
                'visitor_motive_id' => $motiveId,
                'qr_type_id' => QrType::SINGLE_ENTRY,
            ])
            ->assertStatus(201);

        // 4th is rejected — must NOT push used past 3.
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/visitor', [
                'unit_id' => $unit->id,
                'name' => 'Visita 4',
                'visitor_motive_id' => $motiveId,
                'qr_type_id' => QrType::SINGLE_ENTRY,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'QUOTA_EXCEEDED');

        $this->assertSame(3, $this->usedCount($tenant, PlanQuota::RESOURCE_VISITOR), 'Final consumption must be exactly 3, never 4');
    }

    // =========================================================================
    // REQ-CT-05 — one slot, two racers, one winner
    //
    // The sequential test above cannot prove this: it never has two callers in
    // flight at once, so it passes even if the limit is compared ONLY on the
    // unlocked read in checkConsumption(). These two do.
    // =========================================================================

    public function test_a_racer_that_commits_between_check_and_consume_loses_the_last_slot(): void
    {
        // Reproduces the exact READ COMMITTED interleaving, deterministically and
        // without threads: both racers pass the UNLOCKED check at used=2/limit=3,
        // then one commits first. The loser must be rejected when it re-reads under
        // the row lock — if consume() trusted the earlier check, both would write
        // and the counter would land on 4.
        $tenant = $this->makeTenant('Cluster '.Str::random(5));
        $this->seedTenant($tenant);
        $this->seedTrialQuota(PlanQuota::RESOURCE_VISITOR, 3);

        $this->inTenant($tenant, function (): void {
            TenantQuotaUsage::create([
                'resource_key' => PlanQuota::RESOURCE_VISITOR,
                'used_count' => 2,
            ]);

            $service = app(QuotaService::class);

            // Racer B reads first: one slot left, so it is allowed to proceed.
            $check = $service->checkConsumption(PlanQuota::RESOURCE_VISITOR, 1);
            $this->assertTrue($check->allowed, 'the unlocked check must still see room at used=2/limit=3');
            $this->assertSame(3, $check->limit);

            // Racer A wins the lock and takes the last slot.
            TenantQuotaUsage::where('resource_key', PlanQuota::RESOURCE_VISITOR)
                ->update(['used_count' => 3]);

            // Racer B now proceeds with its NOW-STALE permission.
            $rejected = false;
            try {
                DB::transaction(static fn () => $service->consume(PlanQuota::RESOURCE_VISITOR, 1, $check));
            } catch (QuotaExceededException) {
                $rejected = true;
            }

            $this->assertTrue($rejected, 'the loser must be rejected inside the locked section, not admitted on a stale check');
        });

        $this->assertSame(
            3,
            $this->usedCount($tenant, PlanQuota::RESOURCE_VISITOR),
            'over-admission: the counter went past the limit, so the losing racer was let through'
        );
    }

    public function test_consume_selects_the_counter_row_for_update(): void
    {
        // STRUCTURAL pin, and labelled as such on purpose.
        //
        // The honest limitation: a genuine two-racer test of the LOCK needs two
        // OS processes. Inside one PHPUnit process the obvious version is a lie —
        // I wrote it and it passed with `lockForUpdate()` DELETED, because
        // `$row->save()` issues an UPDATE that takes a row lock by itself, so the
        // blocking it observed was never evidence of the SELECT locking anything.
        //
        // What actually differs without the lock: the SELECT reads a stale value
        // instead of waiting, and consume() admits a racer it should reject. The
        // re-check test above pins that consequence deterministically; this one
        // pins the mechanism that makes it hold under real concurrency, by
        // asserting the emitted SQL. It fails the moment `lockForUpdate()` is
        // dropped — which is exactly the regression it exists to catch.
        $tenant = $this->makeTenant('Cluster '.Str::random(5));
        $this->seedTenant($tenant);
        $this->seedTrialQuota(PlanQuota::RESOURCE_VISITOR, 5);

        $this->inTenant($tenant, function (): void {
            TenantQuotaUsage::create([
                'resource_key' => PlanQuota::RESOURCE_VISITOR,
                'used_count' => 0,
            ]);

            DB::enableQueryLog();
            DB::transaction(static fn () => app(QuotaService::class)->consume(PlanQuota::RESOURCE_VISITOR, 1));
            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            $lockingSelects = array_filter(
                $queries,
                static fn (array $q): bool => str_contains(strtolower($q['query']), 'select')
                    && str_contains(strtolower($q['query']), 'tenant_quota_usage')
                    && str_contains(strtolower($q['query']), 'for update')
            );

            $this->assertNotEmpty(
                $lockingSelects,
                'consume() read the counter row without FOR UPDATE — two racers would both read the same stale value'
            );
        });

        $this->assertSame(1, $this->usedCount($tenant, PlanQuota::RESOURCE_VISITOR));
    }
}
