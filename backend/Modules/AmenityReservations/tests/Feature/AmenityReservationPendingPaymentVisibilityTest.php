<?php

declare(strict_types=1);

namespace Modules\AmenityReservations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\AmenityReservation;
use Modules\AmenityReservations\Models\ReservationMode;
use Modules\AmenityReservations\Models\ReservationStatus;
use Modules\AmenityReservations\Support\AmenityReservationsSettings;
use Modules\Collections\Models\PaymentMethod;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Slice B — `has_pending_payment` visibility: AmenityReservationController's
 * buildIndexFetchQuery()/buildShowFetchQuery() expose it via a `withExists`
 * subquery on the `earmark_payments()` relation (no N+1, no accessor).
 * Mirrors ChargePolicyScopeUpdateTest's `has_billed` coverage pattern.
 */
final class AmenityReservationPendingPaymentVisibilityTest extends TestCase
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

    public function test_has_pending_payment_is_true_on_index_and_show_with_a_pending_validation_earmark_payment(): void
    {
        $scn = $this->scenario();

        $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 20000))
            ->assertStatus(201);

        // Left PENDING_VALIDATION deliberately — never validated.

        $index = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->getJson('/api/amenity_reservation')
            ->assertStatus(200)
            ->json('data');
        $byId = collect($index)->keyBy('id');
        $this->assertTrue((bool) $byId[$scn['reservationId']]['has_pending_payment']);

        $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->getJson('/api/amenity_reservation/'.$scn['reservationId'])
            ->assertStatus(200)
            ->assertJsonPath('data.has_pending_payment', true);
    }

    public function test_has_pending_payment_is_false_without_a_pending_validation_earmark_payment(): void
    {
        $scn = $this->scenario();

        $index = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->getJson('/api/amenity_reservation')
            ->assertStatus(200)
            ->json('data');
        $byId = collect($index)->keyBy('id');
        $this->assertFalse((bool) $byId[$scn['reservationId']]['has_pending_payment']);

        $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->getJson('/api/amenity_reservation/'.$scn['reservationId'])
            ->assertStatus(200)
            ->assertJsonPath('data.has_pending_payment', false);
    }

    /**
     * A tenant with the flag enabled, a resident-owned unit, a costed amenity,
     * and a PENDING_PAYMENT reservation created DIRECTLY (bypassing
     * ReservationService::create, which is S6/S7's concern) with the S6 money
     * snapshots already in place. Mirrors AmenityReservationEarmarkTest::scenario().
     *
     * @return array{tenant: Tenant, unit: Unit, account: BankAccount,
     *     residentToken: string, reservationId: string}
     */
    private function scenario(): array
    {
        $tenant = $this->makeTenant('AmenityPendingPaymentVisibility');
        $this->seedTenant($tenant);
        $tenant->run(function (): void {
            Setting::where('slug', AmenityReservationsSettings::ENABLED)->update(['value' => 'true']);
        });

        $unit = null;
        $tenant->run(function () use (&$unit): void {
            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'number' => (string) mt_rand(100, 99999),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
                'ownership_share' => 1.0,
            ]);
        });

        $resident = $this->makeUser($tenant);
        $tenant->run(function () use ($unit, $resident): void {
            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $resident->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
                'is_billing_owner' => true,
            ]);
        });

        $account = null;
        $tenant->run(function () use (&$account): void {
            $account = BankAccount::create([
                'name' => 'Cuenta amenidad',
                'bank_account_type_id' => BankAccountType::CASH,
                'bank_account_purpose_id' => Catalog::id(BankAccountPurpose::class, 'Operativa'),
                'currency' => 'MXN',
                'initial_balance' => 0,
                'current_balance' => 0,
                'is_primary' => true,
                'is_active' => true,
            ]);
        });

        $reservationId = $tenant->run(function () use ($unit, $account): string {
            $amenity = Amenity::create([
                'name' => 'Amenidad '.uniqid(),
                'capacity' => 10,
                'available_weekdays' => [0, 1, 2, 3, 4, 5, 6],
                'reservation_mode_id' => ReservationMode::BY_DAY,
                'has_cost' => true,
                'cost_amount' => 50000,
                'requires_deposit' => false,
                'deposit_amount' => null,
                'deposit_due_days' => null,
                'bank_account_id' => $account->id,
            ]);

            $reservation = AmenityReservation::factory()->create([
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_status_id' => ReservationStatus::PENDING_PAYMENT,
                'holds_slot' => true,
                'cost_amount' => 50000,
                'deposit_amount' => null,
                'amount_outstanding' => 50000,
            ]);

            return $reservation->id;
        });

        return [
            'tenant' => $tenant,
            'unit' => $unit,
            'account' => $account,
            'residentToken' => $resident->createToken('resident', [
                'read.amenity_reservation', 'create.payment_submission',
            ])->plainTextToken,
            'reservationId' => $reservationId,
        ];
    }

    /**
     * @param  array{unit: Unit, account: BankAccount, reservationId: string}  $scn
     * @return array<string, mixed>
     */
    private function submitPayload(array $scn, int $amount): array
    {
        return [
            'unit_id' => $scn['unit']->id,
            'paid_at' => '2026-07-01',
            'total_amount' => $amount,
            // TRANSFER, not CASH (Slice C): resident self-submissions cannot use cash.
            'payment_method_id' => PaymentMethod::TRANSFER,
            'bank_account_id' => $scn['account']->id,
            'earmark_type' => 'amenity_reservation',
            'earmark_id' => $scn['reservationId'],
            // Any non-cash method REQUIRES a comprobante at the controller
            // (pre-existing §8.7.1 r5 gate, unrelated to Slice C but now
            // reachable since these tests no longer submit as cash). A REAL
            // fake image (not ::create(), which reports a size/mime without
            // real bytes) — Spatie MediaLibrary sniffs actual file content.
            'receipt' => UploadedFile::fake()->image('comprobante.png'),
        ];
    }
}
