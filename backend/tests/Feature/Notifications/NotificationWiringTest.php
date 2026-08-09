<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Access\Models\QrType;
use Modules\Access\Models\VisitorMotive;
use Modules\Access\Services\AccessService;
use Modules\Board\Models\BoardRole;
use Modules\Board\Services\BoardService;
use Modules\Chat\Models\ChatChannel;
use Modules\Chat\Models\ChatChannelType;
use Modules\Chat\Services\ChatService;
use Modules\Collaborator\Models\Collaborator;
use Modules\Collaborator\Services\EmployeeService;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\ChargeBatch;
use Modules\Collections\Models\ChargeBatchStatus;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Services\ChargeGenerationService;
use Modules\Collections\Services\PaymentService;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Housing\Models\Vehicle;
use Modules\Housing\Services\UnitUserService;
use Modules\Notification\Models\Notification;
use Modules\Notification\Models\NotificationUser;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Modules\Treasury\Models\BeneficiaryType;
use Modules\Treasury\Models\ExpenseCategory;
use Modules\Treasury\Services\ExpenseService;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Notification WIRING tests (§17 consolidated): proves each module's domain events
 * dispatch through the existing NotificationDispatcher (log driver) AND record the
 * in-app Notification + NotificationUser rows, to the correct recipients.
 *
 * In-app Notification rows are the assertion of record (DB-observable, deterministic).
 * The log driver is exercised implicitly (config default 'log') but not asserted on.
 *
 * NOTE: these suites use $connectionsToTransact = [] (no wrapping test transaction),
 * so the NotifiesResidents DB::afterCommit callbacks fire when each service's own
 * DB::transaction commits (or immediately when no transaction is open).
 */
final class NotificationWiringTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGlobalRoles();
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        Carbon::setTestNow();

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function seedGlobalRoles(): void
    {
        $slugs = ['president', 'treasurer', 'secretary', 'administrator', 'security', 'resident', 'renter'];
        foreach ($slugs as $slug) {
            Role::firstOrCreate(['name' => $slug, 'guard_name' => 'api'], ['name' => $slug, 'guard_name' => 'api']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeCentralUser(string $name = 'Resident User', ?string $roleSlug = null): User
    {
        $user = User::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'email' => Str::random(8).'@test.com',
            'password' => bcrypt('password'),
        ]);

        if ($roleSlug) {
            $user->assignRole($roleSlug);
        }

        return $user;
    }

    private function makeUnit(Tenant $tenant): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit): void {
            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));
            $unit = Unit::create([
                'unit_type_id' => $unitType->id,
                'number' => (string) mt_rand(100, 9999),
                'unit_status_id' => $unitStatus->id,
            ]);
        });

        return $unit;
    }

    /**
     * Attach a user as the billing owner (titular de pago) of a unit.
     */
    private function attachBillingOwner(Tenant $tenant, Unit $unit, User $user): void
    {
        $tenant->run(function () use ($unit, $user): void {
            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
                'is_billing_owner' => true,
            ]);
        });
    }

    private function makeBankAccount(Tenant $tenant, int $initialBalanceRaw = 0): BankAccount
    {
        $account = null;
        $tenant->run(function () use (&$account, $initialBalanceRaw): void {
            $type = BankAccountType::findOrFail(BankAccountType::CASH);
            $purpose = BankAccountPurpose::findOrFail(Catalog::id(BankAccountPurpose::class, 'Operativa'));
            $account = BankAccount::create([
                'name' => 'Caja efectivo',
                'bank_account_type_id' => $type->id,
                'bank_account_purpose_id' => $purpose->id,
                'currency' => 'MXN',
                'initial_balance' => $initialBalanceRaw,
                'current_balance' => $initialBalanceRaw,
                'is_primary' => true,
                'is_active' => true,
            ]);
        });

        return $account;
    }

    /**
     * Assert a NotificationUser row exists for the given user with a title that contains
     * the expected fragment (case-insensitive). Must be called inside the tenant context.
     */
    private function assertUserNotified(string $userId, string $titleFragment): void
    {
        $exists = NotificationUser::query()
            ->where('user_id', $userId)
            ->whereHas('notification', function ($q) use ($titleFragment): void {
                $q->where('title', 'like', '%'.$titleFragment.'%');
            })
            ->exists();

        $this->assertTrue(
            $exists,
            "Expected an in-app notification titled like '{$titleFragment}' for user {$userId}."
        );
    }

    // =========================================================================
    // Collections — payment registered → titular notified
    // =========================================================================

    public function test_payment_registered_notifies_titular(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $titular = $this->makeCentralUser('Titular Pago');
        $unit = $this->makeUnit($tenant);
        $this->attachBillingOwner($tenant, $unit, $titular);
        $bankAccount = $this->makeBankAccount($tenant);

        $tenant->run(function () use ($unit, $bankAccount, $titular): void {
            $method = PaymentMethod::findOrFail(PaymentMethod::CASH);

            app(PaymentService::class)->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-10',
                'total_amount' => 50000,
                'payment_method_id' => $method->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            $this->assertUserNotified($titular->id, 'Pago registrado');
        });
    }

    public function test_payment_surplus_notifies_titular_of_credit_balance(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $titular = $this->makeCentralUser('Titular Credito');
        $unit = $this->makeUnit($tenant);
        $this->attachBillingOwner($tenant, $unit, $titular);
        $bankAccount = $this->makeBankAccount($tenant);

        $tenant->run(function () use ($unit, $bankAccount, $titular): void {
            $method = PaymentMethod::findOrFail(PaymentMethod::CASH);

            // No open charges → the whole payment becomes credit.
            app(PaymentService::class)->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-10',
                'total_amount' => 25000,
                'payment_method_id' => $method->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            $this->assertUserNotified($titular->id, 'Saldo a favor');
        });
    }

    // =========================================================================
    // Collections — charge batch activated → affected unit's titular notified
    // =========================================================================

    public function test_charge_batch_activation_notifies_affected_units(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $titular = $this->makeCentralUser('Titular Cobro');
        $unit = $this->makeUnit($tenant);
        $this->attachBillingOwner($tenant, $unit, $titular);

        $tenant->run(function () use ($titular): void {
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));

            $concept = ChargeConcept::create([
                'code' => 'MANT_'.mt_rand(1000, 99999),
                'name' => 'Mantenimiento mensual',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);

            $policy = ChargePolicy::create([
                'charge_concept_id' => $concept->id,
                'name' => 'Política mensual',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => 70000,
                'periodicity_id' => Periodicity::MONTHLY,
                'valid_from' => '2026-01-01',
                'is_active' => true,
                'grace_days' => 0,
            ]);

            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
            $batch = ChargeBatch::create([
                'charge_policy_id' => $policy->id,
                'title' => 'Mantenimiento Marzo 2026',
                'year' => 2026,
                'month' => 3,
                'target_type' => 'all',
                'target_selector' => null,
                'issued_at' => '2026-03-01',
                'due_date' => '2026-03-15',
                'charge_batch_status_id' => $draftStatus->id,
                'progress_pct' => 0,
            ]);

            app(ChargeGenerationService::class)->activateBatch($batch);

            $this->assertUserNotified($titular->id, 'Nuevo cargo');
        });
    }

    // =========================================================================
    // Treasury — expense > $5,000 → President notified
    // =========================================================================

    public function test_large_expense_notifies_president(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeCentralUser('Presidente', 'president');
        $president->tenants()->attach($tenant->id);
        $bankAccount = $this->makeBankAccount($tenant, 2000000); // $20,000 so balance stays high

        $tenant->run(function () use ($bankAccount, $president): void {
            $category = ExpenseCategory::firstOrCreate(
                ['name' => 'Egreso Wiring Test', 'parent_id' => null],
                ['order' => 99, 'is_active' => true],
            );
            $beneficiaryTypeId = BeneficiaryType::OTHER;

            app(ExpenseService::class)->register([
                'concept' => 'Reparación mayor',
                'bank_account_id' => $bankAccount->id,
                'expense_category_id' => $category->id,
                'beneficiary_type_id' => $beneficiaryTypeId,
                'payment_method_id' => PaymentMethod::TRANSFER,
                'amount' => 600000, // $6,000 > $5,000 threshold
                'year' => 2026,
                'month' => 3,
                'paid_at' => '2026-03-10',
            ], force: true);

            $this->assertUserNotified($president->id, 'Egreso elevado');
        });
    }

    // =========================================================================
    // Housing — person associated to unit → person notified
    // =========================================================================

    public function test_unit_user_association_notifies_person(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $person = $this->makeCentralUser('Nuevo Propietario');
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit, $person): void {
            app(UnitUserService::class)->create([
                'unit_id' => $unit->id,
                'user_id' => $person->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
                'is_billing_owner' => true,
            ]);

            $this->assertUserNotified($person->id, 'Asignación a unidad');
        });
    }

    // =========================================================================
    // Housing — vehicle registered → titular notified (observer)
    // =========================================================================

    public function test_vehicle_registration_notifies_titular(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $titular = $this->makeCentralUser('Titular Vehiculo');
        $unit = $this->makeUnit($tenant);
        $this->attachBillingOwner($tenant, $unit, $titular);

        $tenant->run(function () use ($unit, $titular): void {
            Vehicle::create([
                'unit_id' => $unit->id,
                'plate' => 'ABC-123',
                'make' => 'Toyota',
                'model' => 'Corolla',
            ]);

            $this->assertUserNotified($titular->id, 'Vehículo registrado');
        });
    }

    // =========================================================================
    // Housing — unit marked inactive → residents notified (observer)
    // =========================================================================

    public function test_unit_marked_inactive_notifies_residents(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeCentralUser('Residente Inactivo');
        $unit = $this->makeUnit($tenant);
        $this->attachBillingOwner($tenant, $unit, $resident);

        $tenant->run(function () use ($unit, $resident): void {
            $inactive = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Inactiva'));
            $unit->unit_status_id = $inactive->id;
            $unit->save();

            $this->assertUserNotified($resident->id, 'inactiva');
        });
    }

    // =========================================================================
    // Access — QR scan success → unit residents notified
    // =========================================================================

    public function test_qr_scan_notifies_unit_residents(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeCentralUser('Residente Acceso');
        $unit = $this->makeUnit($tenant);
        $this->attachBillingOwner($tenant, $unit, $resident);

        $tenant->run(function () use ($unit, $resident): void {
            $service = app(AccessService::class);
            $motive = VisitorMotive::findOrFail(Catalog::id(VisitorMotive::class, 'Social / familiar'));
            $qrType = QrType::findOrFail(QrType::SINGLE_ENTRY);

            $visitor = $service->generateQr([
                'unit_id' => $unit->id,
                'name' => 'Visita QR',
                'visitor_motive_id' => $motive->id,
                'qr_type_id' => $qrType->id,
            ]);

            $result = $service->scanQr($visitor->qr_token);
            $this->assertTrue($result['valid']);

            $this->assertUserNotified($resident->id, 'Visita en puerta');
        });
    }

    // =========================================================================
    // Access — QR cancelled by resident → guards notified
    // =========================================================================

    public function test_qr_cancel_notifies_guards(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        // Role recipients are tenant-scoped: the guard must be a member of the tenant.
        $guard = $this->makeCentralUser('Vigilante', 'security');
        $guard->tenants()->attach($tenant->id);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit, $guard): void {
            $service = app(AccessService::class);
            $motive = VisitorMotive::findOrFail(Catalog::id(VisitorMotive::class, 'Social / familiar'));
            $qrType = QrType::findOrFail(QrType::SINGLE_ENTRY);

            $visitor = $service->generateQr([
                'unit_id' => $unit->id,
                'name' => 'Visita a cancelar',
                'visitor_motive_id' => $motive->id,
                'qr_type_id' => $qrType->id,
            ]);

            $service->cancelQr($visitor);

            $this->assertUserNotified($guard->id, 'cancelado');
        });
    }

    // =========================================================================
    // Board — new period → assigned members + residents notified
    // =========================================================================

    public function test_board_period_start_notifies_members_and_residents(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeCentralUser('Presidente Board');
        $treasurer = $this->makeCentralUser('Tesorero Board');
        $secretary = $this->makeCentralUser('Secretario Board');
        // Board members must be tenant members for role-based recipient resolution
        // (Spatie role ∩ user_tenant), mirroring production where they are cluster users.
        $president->tenants()->attach($tenant->id);
        $treasurer->tenants()->attach($tenant->id);
        $secretary->tenants()->attach($tenant->id);
        $resident = $this->makeCentralUser('Vecino', 'resident');
        $resident->tenants()->attach($tenant->id);

        $tenant->run(function () use ($president, $treasurer, $secretary, $resident): void {
            $members = [
                ['user_id' => $president->id, 'board_role_id' => BoardRole::PRESIDENT, 'unit_id' => null],
                ['user_id' => $treasurer->id, 'board_role_id' => BoardRole::TREASURER, 'unit_id' => null],
                ['user_id' => $secretary->id, 'board_role_id' => BoardRole::SECRETARY, 'unit_id' => null],
            ];

            app(BoardService::class)->startPeriod([
                'name' => 'Mesa 2026-2028',
                'start_date' => '2026-01-01',
                'end_date' => '2028-01-01',
                'auto_close_current' => true,
            ], $members);

            // Each assigned member gets an assignment notification.
            $this->assertUserNotified($president->id, 'Asignación a la mesa');
            // ADR-4: new_board notifies the configured board roles (default President +
            // Secretary), not all residents. Residents learn via the auto-created
            // cluster announcement instead. $resident above must NOT get this event.
            $this->assertUserNotified($secretary->id, 'Nueva mesa directiva');
        });
    }

    // =========================================================================
    // Collaborator — employee terminated → Administrator notified
    // =========================================================================

    public function test_employee_termination_notifies_administrator(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeCentralUser('Administrador', 'administrator');
        $admin->tenants()->attach($tenant->id);
        $employeeUser = $this->makeCentralUser('Empleado');

        $tenant->run(function () use ($admin, $employeeUser): void {
            $employee = Collaborator::create([
                'user_id' => $employeeUser->id,
                'name' => 'Empleado Baja',
                'is_active' => true,
            ]);

            app(EmployeeService::class)->terminate(
                $employee,
                '2026-03-31',
                'Fin de contrato',
                null,
                false,
            );

            $this->assertUserNotified($admin->id, 'Empleado dado de baja');
        });
    }

    // =========================================================================
    // Chat — message in channel → other members notified
    // =========================================================================

    public function test_chat_message_notifies_channel_members(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $author = $this->makeCentralUser('Autor');
        $member = $this->makeCentralUser('Miembro');
        // Notifications are audience-based via the unit target system, so both must
        // be active residents to be in the 'all' channel audience.
        $this->attachBillingOwner($tenant, $this->makeUnit($tenant), $author);
        $this->attachBillingOwner($tenant, $this->makeUnit($tenant), $member);

        $tenant->run(function () use ($author, $member): void {
            $type = ChatChannelType::findOrFail(ChatChannelType::GENERAL);
            $channel = ChatChannel::create([
                'name' => 'Canal de prueba',
                'chat_channel_type_id' => $type->id,
                'target_type' => 'all',
                'target_selector' => null,
                'mode' => 'everyone_writes',
                'is_moderated' => false,
                'is_system' => false,
                'is_archived' => false,
            ]);

            $service = app(ChatService::class);
            $service->joinChannel($channel, $author->id, 'all');
            $service->joinChannel($channel, $member->id, 'all');

            $service->postMessage($channel, $author->id, 'Hola a todos');

            $this->assertUserNotified($member->id, 'Nuevo mensaje');

            // Author must NOT be notified of their own message.
            $authorNotified = NotificationUser::where('user_id', $author->id)
                ->whereHas('notification', fn ($q) => $q->where('title', 'like', '%Nuevo mensaje%'))
                ->exists();
            $this->assertFalse($authorNotified, 'Author must not be notified of own message');
        });
    }
}
