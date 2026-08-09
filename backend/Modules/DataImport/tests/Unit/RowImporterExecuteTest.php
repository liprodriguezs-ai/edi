<?php

namespace Modules\DataImport\Tests\Unit;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Collections\Jobs\GenerateReceiptPdfJob;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\CreditBalance;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Models\PaymentStatus;
use Modules\Collections\Models\Periodicity;
use Modules\DataImport\Services\ReferenceResolver;
use Modules\DataImport\Services\RowImporter;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Modules\User\Models\UserInvitation;
use Modules\UserProfile\Models\UserProfile;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Unit tests for RowImporter::execute() — covering idempotency and FIFO scenarios.
 *
 * Sub-cases:
 *  A — re-run same file → 0 new payments; PaymentService not called 2nd time
 *  B — existing physical_receipt_number → SKIP, no new application
 *  C — two distinct same-day same-amount rows without folio → first created, second SKIPPED
 *  D — re-submit + 1 new row → exactly 1 new payment
 *  E — FIFO: 2 charges $500 + payment $700 → oldest full(50000), second partial(20000)
 *  F — surplus → credit_balance(30000)
 *  G — auto-create tipo_unidad + warning emitted
 *  H — auto-create cuenta_destino (BankAccountType::CASH) + warning emitted
 *  I — a CANCELLED same-key (and same-folio) payment does NOT block a new import
 */
class RowImporterExecuteTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected $connectionsToTransact = [];

    protected Tenant $tenant;

    protected RowImporter $importer;

    protected ReferenceResolver $resolver;

    protected UnitType $unitType;

    protected UnitStatus $unitStatus;

    protected BankAccount $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([TenantCreated::class]);
        $this->tenant = $this->makeTenant();
        $this->seedTenant($this->tenant);
        tenancy()->initialize($this->tenant);

        // Fake queues to prevent receipt PDF job from running
        Queue::fake();

        $this->resolver = new ReferenceResolver;
        $this->importer = new RowImporter($this->resolver);

        // TenantDatabaseSeeder already creates unit_type "Casa" and unit_status "Activa"
        // via TenantCatalogSeeder. Use firstOrCreate to avoid duplicates.
        $this->unitType = UnitType::firstOrCreate(
            ['name' => 'Casa', 'tenant_id' => tenant('id')],
            ['order' => 1, 'is_active' => true, 'requires_floor' => false]
        );

        $this->unitStatus = UnitStatus::firstOrCreate(
            ['name' => 'Activa', 'tenant_id' => tenant('id')],
            ['order' => 1, 'is_active' => true, 'is_billable' => true]
        );

        // BankAccountPurpose is also seeded by TenantCatalogSeeder — use first or create
        $purpose = BankAccountPurpose::where('tenant_id', tenant('id'))->first()
            ?? BankAccountPurpose::create([
                'name' => 'General',
                'order' => 1,
                'is_active' => true,
            ]);

        $this->bankAccount = BankAccount::firstOrCreate(
            ['name' => 'Caja Principal', 'tenant_id' => tenant('id')],
            [
                'bank_account_type_id' => BankAccountType::CASH,
                'bank_account_purpose_id' => $purpose->id,
                'currency' => 'MXN',
                'initial_balance' => 0,
                'is_primary' => true,
                'is_active' => true,
            ]
        );
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

    /**
     * Build a valid row that can be executed without issues.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validRow(array $overrides = []): array
    {
        return array_merge([
            'tipo_unidad' => 'Casa',
            'numero' => '101',
            'torre' => null,
            'piso' => null,
            'seccion' => null,
            'estado_unidad' => 'Activa',
            'indiviso' => null,
            'm2_construido' => null,
            'm2_terreno' => null,
            'nombre' => 'Juan',
            'apellido_paterno' => 'Pérez',
            'apellido_materno' => null,
            'email' => null,
            'telefono' => null,
            'relacion' => null,
            'titular_de_pago' => null,
            'rfc' => null,
            'curp' => null,
            'fecha_nacimiento' => null,
            'fecha_pago' => '2024-03-15',
            'monto' => '1000.00', // $10.00 MXN = 1000 centavos
            'metodo_pago' => null,
            'cuenta_destino' => 'Caja Principal',
            'referencia' => null,
            'folio' => null,
            'notas' => null,
        ], $overrides);
    }

    /**
     * Resolve the resolver fresh for a new run (re-using the same resolver between
     * two execute() calls simulates what ImportRunner does across a file's rows,
     * but sub-cases that test second-run idempotency create a new resolver instance).
     */
    private function freshResolver(): ReferenceResolver
    {
        return new ReferenceResolver;
    }

    /** Monotonically increasing offset so sequential charges get distinct due_dates for stable FIFO ordering. */
    private int $chargeIndex = 0;

    /**
     * Create an open Charge for a unit (for FIFO testing).
     * expected_amount and paid_amount are in minor units (bigint centavos).
     *
     * Creates a minimal ChargeConcept first (requires FK chain: category + amount_mode + periodicity).
     * amount_mode + periodicity are CENTRAL catalogs (CatalogSeeder); charge_concept_category is
     * TENANT-scoped (TenantCatalogSeeder) and is resolved by tenant_id below.
     *
     * Each call gets a progressively later due_date so FIFO ordering is deterministic.
     */
    private function createOpenCharge(Unit $unit, int $expectedAmount): Charge
    {
        $chargeStatus = ChargeStatus::findOrFail(ChargeStatus::PENDING);

        // charge_concept_id is NOT NULL in charge — create a minimal identity concept via DB.
        // Charge behavior (amount/periodicity) lives on charge_policy now, not needed here.
        $conceptId = (string) Str::uuid();
        DB::table('charge_concept')->insert([
            'id' => $conceptId,
            'tenant_id' => tenant('id'),
            'code' => 'TEST-'.uniqid(),
            'name' => 'Mantenimiento Test',
            'charge_concept_category_id' => DB::table('charge_concept_category')
                ->where('tenant_id', tenant('id'))
                ->value('id'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Use chargeIndex to ensure distinct due_dates for deterministic FIFO ordering
        $dueDate = now()->startOfMonth()->addDays($this->chargeIndex)->format('Y-m-d');
        $this->chargeIndex++;

        $chargeId = (string) Str::uuid();
        DB::table('charge')->insert([
            'id' => $chargeId,
            'tenant_id' => tenant('id'),
            'unit_id' => $unit->id,
            'charge_batch_id' => null,
            'charge_concept_id' => $conceptId,
            'expected_amount' => $expectedAmount,
            'paid_amount' => 0,
            'discount_amount' => 0,
            'due_date' => $dueDate,
            'issued_at' => now(),
            'grace_days' => 0,
            'is_cancelled' => false,
            'charge_status_id' => $chargeStatus->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Charge::findOrFail($chargeId);
    }

    // =========================================================================
    // Sub-case A — re-run same file → 0 new payments; PaymentService not called 2nd time
    // =========================================================================

    public function test_a_rerun_same_file_produces_zero_new_payments(): void
    {
        $row = $this->validRow();
        $resolver = $this->freshResolver();

        // First run
        $result1 = $this->importer->execute($row, 2, $resolver);
        $this->assertTrue($result1->paymentRegistered);
        $this->assertFalse($result1->skipped);
        $paymentCountAfterFirst = Payment::count();

        // Second run — same row, new resolver (simulates re-upload of same file)
        $resolver2 = $this->freshResolver();
        $result2 = $this->importer->execute($row, 2, $resolver2);
        $this->assertTrue($result2->skipped);
        $this->assertFalse($result2->paymentRegistered);

        // Zero new payments after second run
        $this->assertSame($paymentCountAfterFirst, Payment::count());
    }

    // =========================================================================
    // Sub-case B — existing physical_receipt_number → SKIP, no new application
    // =========================================================================

    public function test_b_existing_folio_skips_row_without_creating_payment(): void
    {
        // Execute first time with folio
        $row = $this->validRow(['folio' => 'FOLIO-001', 'monto' => '500.00']);
        $resolver = $this->freshResolver();

        $result1 = $this->importer->execute($row, 2, $resolver);
        $this->assertTrue($result1->paymentRegistered);

        $paymentsBefore = Payment::count();

        // Execute second time with same folio
        $resolver2 = $this->freshResolver();
        $result2 = $this->importer->execute($row, 2, $resolver2);

        $this->assertTrue($result2->skipped, 'Row with existing folio must be skipped');
        $this->assertFalse($result2->paymentRegistered);
        $this->assertSame($paymentsBefore, Payment::count(), 'No new payment should be created');

        // Warning issued about folio skip
        $hasFolioWarning = collect($result2->issues)->contains(
            fn ($i) => $i['column'] === 'folio' && $i['severity'] === 'warning'
        );
        $this->assertTrue($hasFolioWarning, 'A folio warning should be emitted on skip');
    }

    // =========================================================================
    // Sub-case C — two distinct same-day same-amount rows without folio → first created, second SKIPPED
    // =========================================================================

    public function test_c_same_day_same_amount_no_folio_second_row_skipped(): void
    {
        $row = $this->validRow([
            'fecha_pago' => '2024-05-10',
            'monto' => '1500.00',
            'folio' => null,
        ]);

        $resolver = $this->freshResolver();

        // First execution
        $result1 = $this->importer->execute($row, 2, $resolver);
        $this->assertTrue($result1->paymentRegistered, 'First payment should be registered');
        $this->assertFalse($result1->skipped);

        $paymentCountAfterFirst = Payment::count();

        // Second execution — same unit, same date, same amount, no folio
        $resolver2 = $this->freshResolver();
        $result2 = $this->importer->execute($row, 3, $resolver2);

        $this->assertTrue($result2->skipped, 'Second same-day same-amount row must be skipped (design-flagged behavior)');
        $this->assertSame($paymentCountAfterFirst, Payment::count(), 'No new payment on natural-key collision');
    }

    // =========================================================================
    // Sub-case D — re-submit + 1 new row → exactly 1 new payment
    // =========================================================================

    public function test_d_resubmit_with_one_new_row_registers_exactly_one_payment(): void
    {
        $rowA = $this->validRow(['numero' => '101', 'fecha_pago' => '2024-04-01', 'monto' => '800.00']);
        $rowB = $this->validRow(['numero' => '202', 'fecha_pago' => '2024-04-02', 'monto' => '900.00']);

        $resolver = $this->freshResolver();

        // First file: rowA only
        $this->importer->execute($rowA, 2, $resolver);
        $afterFirstRun = Payment::count();

        // Re-submit with rowA (old) + rowB (new)
        $resolver2 = $this->freshResolver();
        $result2A = $this->importer->execute($rowA, 2, $resolver2); // should skip
        $result2B = $this->importer->execute($rowB, 3, $resolver2); // should register

        $this->assertTrue($result2A->skipped, 'Existing row A must be skipped');
        $this->assertTrue($result2B->paymentRegistered, 'New row B must register a payment');

        // Exactly 1 new payment total
        $this->assertSame($afterFirstRun + 1, Payment::count());
    }

    // =========================================================================
    // Sub-case E — FIFO: 2 charges $500 + payment $700 → oldest full, second partial
    // =========================================================================

    public function test_e_fifo_two_charges_payment_partially_covers_second(): void
    {
        $row = $this->validRow(['monto' => '700.00']); // 70000 centavos
        $resolver = $this->freshResolver();

        // First: create the unit and resident so we can attach charges to it
        $unitType = $this->unitType;
        $unitStatus = $this->unitStatus;

        $unit = Unit::create([
            'unit_type_id' => $unitType->id,
            'number' => '101',
            'unit_status_id' => $unitStatus->id,
        ]);

        // Create 2 open charges of 50000 each (= $500 MXN)
        $charge1 = $this->createOpenCharge($unit, 50000); // oldest
        $charge2 = $this->createOpenCharge($unit, 50000); // second

        // Execute the row (will find existing unit, create resident, register payment)
        $result = $this->importer->execute($row, 2, $resolver);

        $this->assertTrue($result->paymentRegistered, 'Payment must be registered');
        $this->assertFalse($result->skipped);

        // Refresh charges
        $charge1->refresh();
        $charge2->refresh();

        // FIFO: oldest charge fully paid (50000), second partially paid (20000)
        $this->assertSame(50000, (int) $charge1->getAttributes()['paid_amount'], 'Oldest charge should be fully applied');
        $this->assertSame(20000, (int) $charge2->getAttributes()['paid_amount'], 'Second charge should be partially applied');
    }

    // =========================================================================
    // Sub-case F — surplus → credit_balance
    // =========================================================================

    public function test_f_surplus_payment_creates_credit_balance(): void
    {
        $row = $this->validRow(['monto' => '300.00']); // 30000 centavos, no open charges
        $resolver = $this->freshResolver();

        $result = $this->importer->execute($row, 2, $resolver);

        $this->assertTrue($result->paymentRegistered);
        $this->assertFalse($result->skipped);

        // No open charges → full amount becomes credit balance
        $unit = Unit::where('number', '101')
            ->where('tenant_id', tenant('id'))
            ->first();

        $this->assertNotNull($unit);

        $creditBalance = CreditBalance::where('unit_id', $unit->id)->first();
        $this->assertNotNull($creditBalance, 'Credit balance must be created');
        $this->assertSame(30000, (int) $creditBalance->getAttributes()['amount']);
    }

    // =========================================================================
    // Sub-case G — auto-create tipo_unidad + warning emitted
    // =========================================================================

    public function test_g_auto_creates_missing_unit_type_with_warning(): void
    {
        $row = $this->validRow(['tipo_unidad' => 'Penthouse', 'numero' => '500']);
        $resolver = $this->freshResolver();

        $unitTypeCountBefore = UnitType::where('tenant_id', tenant('id'))->count();

        $result = $this->importer->execute($row, 2, $resolver);

        // A new UnitType must have been created
        $this->assertSame($unitTypeCountBefore + 1, UnitType::where('tenant_id', tenant('id'))->count());

        $newType = UnitType::where('name', 'Penthouse')
            ->where('tenant_id', tenant('id'))
            ->first();
        $this->assertNotNull($newType, 'Penthouse unit type must be auto-created');

        // Warning must be emitted for tipo_unidad
        $hasTipoWarning = collect($result->issues)->contains(
            fn ($i) => $i['column'] === 'tipo_unidad' && $i['severity'] === 'warning'
        );
        $this->assertTrue($hasTipoWarning, 'Warning must be emitted for auto-created unit type');

        // Payment must still be registered (not blocked)
        $this->assertTrue($result->paymentRegistered);
    }

    // =========================================================================
    // Sub-case H — auto-create cuenta_destino (BankAccountType::CASH) + warning emitted
    // =========================================================================

    public function test_h_auto_creates_missing_bank_account_of_type_cash_with_warning(): void
    {
        $row = $this->validRow(['cuenta_destino' => 'Caja Secundaria']);
        $resolver = $this->freshResolver();

        $bankAccountCountBefore = BankAccount::where('tenant_id', tenant('id'))->count();

        $result = $this->importer->execute($row, 2, $resolver);

        // A new BankAccount must have been created
        $this->assertSame($bankAccountCountBefore + 1, BankAccount::where('tenant_id', tenant('id'))->count());

        $newAccount = BankAccount::where('name', 'Caja Secundaria')
            ->where('tenant_id', tenant('id'))
            ->first();
        $this->assertNotNull($newAccount, 'Caja Secundaria bank account must be auto-created');
        $this->assertSame(BankAccountType::CASH, $newAccount->bank_account_type_id, 'Auto-created account must be type Caja');

        // Warning must be emitted for cuenta_destino
        $hasCuentaWarning = collect($result->issues)->contains(
            fn ($i) => $i['column'] === 'cuenta_destino' && $i['severity'] === 'warning'
        );
        $this->assertTrue($hasCuentaWarning, 'Warning must be emitted for auto-created bank account');

        // Payment must still be registered
        $this->assertTrue($result->paymentRegistered);
    }

    // =========================================================================
    // Sub-case I — a CANCELLED same-key (and same-folio) payment must NOT block a new import
    // =========================================================================

    public function test_i_cancelled_payment_does_not_block_new_payment(): void
    {
        // validRow default natural key: unit Casa/101, paid_at 2024-03-15, monto 1000.00 (100000 minor units).
        $row = $this->validRow(['folio' => 'FOLIO-CANCEL']);

        // Pre-create the unit so the cancelled payment can reference it.
        $unit = Unit::create([
            'unit_type_id' => $this->unitType->id,
            'number' => '101',
            'unit_status_id' => $this->unitStatus->id,
        ]);

        // Seed a CANCELLED payment that matches BOTH idempotency keys
        // (same folio AND same unit_id + paid_at + total_amount). Inserted directly
        // (raw minor units) to bypass the money cast and the receipt-number generator.
        DB::table('payment')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => tenant('id'),
            'unit_id' => $unit->id,
            'paid_at' => '2024-03-15',
            'total_amount' => 100000, // 1000.00 MXN in minor units — same as the row
            'payment_method_id' => PaymentMethod::CASH,
            'bank_account_id' => $this->bankAccount->id,
            'receipt_number' => 'R-2024-999999',
            'physical_receipt_number' => 'FOLIO-CANCEL',
            'payment_status_id' => PaymentStatus::CANCELLED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $paymentsBefore = Payment::count();

        // Importing a legitimate re-payment must NOT be skipped by the cancelled record.
        $result = $this->importer->execute($row, 2, $this->freshResolver());

        $this->assertTrue($result->paymentRegistered, 'A cancelled match must not skip a new payment');
        $this->assertFalse($result->skipped, 'Row must not be skipped by a cancelled payment');
        $this->assertSame($paymentsBefore + 1, Payment::count(), 'Exactly one new payment must be created');
    }

    // =========================================================================
    // Sub-case J — bulk import is silent: no per-payment receipt PDF / notifications
    // =========================================================================

    public function test_j_import_does_not_dispatch_receipt_pdf_job(): void
    {
        // The importer calls PaymentService::register(..., silent: true) so a historical
        // bulk load never queues a per-payment receipt PDF (which previously flooded the
        // queue, one GenerateReceiptPdfJob per payment) nor notifies residents about old
        // payments.
        $row = $this->validRow(['numero' => 'SILENT-1', 'folio' => 'SILENT-FOLIO-1']);

        $result = $this->importer->execute($row, 2, $this->freshResolver());

        $this->assertTrue($result->paymentRegistered, 'The payment must still be registered');
        Queue::assertNotPushed(GenerateReceiptPdfJob::class);
    }

    // =========================================================================
    // Sub-case K — plan 056: an email resolving to an EXISTING user who belongs to
    // a DIFFERENT tenant must NOT be silently attached. An invitation is created
    // instead, and no unit_user link is made until it is accepted.
    // =========================================================================

    public function test_k_cross_tenant_existing_user_is_not_attached_and_gets_invited(): void
    {
        Mail::fake();

        // A real account that belongs to a DIFFERENT cluster (tenant B) only.
        $tenantB = $this->makeTenant('Cluster B');
        $this->seedTenant($tenantB);
        $userB = $this->makeUser($tenantB);

        $row = $this->validRow(['email' => $userB->email, 'numero' => '301']);
        $resolver = $this->freshResolver();

        $result = $this->importer->execute($row, 2, $resolver);

        // The historical payment still registers — only the enrollment is gated.
        $this->assertTrue($result->paymentRegistered);
        $this->assertFalse($result->residentCreated, 'an existing global account must not be reported as a newly created resident');

        // The victim must NOT be attached to tenant A (the importing tenant).
        $this->assertFalse(
            $userB->tenants()->where('tenant.id', $this->tenant->id)->exists(),
            'an existing out-of-tenant account must never be silently attached by import'
        );

        // No unit_user link was created for the non-consented user.
        $unit = Unit::where('number', '301')->where('tenant_id', tenant('id'))->firstOrFail();
        $this->assertFalse(
            UnitUser::where('unit_id', $unit->id)->where('user_id', $userB->id)->exists(),
            'no unit_user link must be created before the invitation is accepted'
        );

        // An invitation was created for this email, targeting this unit in this tenant.
        $invitation = UserInvitation::where('email', $userB->email)
            ->where('tenant_id', tenant('id'))
            ->first();
        $this->assertNotNull($invitation, 'a UserInvitation must be created instead of a silent attach');
        $this->assertSame(Unit::class, $invitation->inviteable_type);
        $this->assertSame($unit->id, $invitation->inviteable_id);
        $this->assertNull($invitation->accepted_at);

        // The row surfaces a non-blocking warning about the invitation.
        $hasInvitationWarning = collect($result->issues)->contains(
            fn ($i) => $i['column'] === 'email' && $i['severity'] === 'warning' && str_contains($i['message'], 'invitación')
        );
        $this->assertTrue($hasInvitationWarning, 'a warning about the sent invitation must be surfaced');
    }

    // =========================================================================
    // Sub-case L — plan 056: a user already a member of THIS tenant, re-imported,
    // stays idempotent — no duplicate membership, no invitation, no error.
    // =========================================================================

    public function test_l_same_tenant_existing_user_reimport_is_idempotent(): void
    {
        $existingUser = $this->makeUser($this->tenant);

        $row = $this->validRow(['email' => $existingUser->email, 'numero' => '302']);
        $resolver = $this->freshResolver();

        $result = $this->importer->execute($row, 2, $resolver);

        $this->assertTrue($result->paymentRegistered);
        $this->assertFalse($result->residentCreated, 'an already-existing tenant member must not be reported as newly created');

        // Exactly one membership row — no duplicate attach.
        $this->assertSame(
            1,
            $existingUser->tenants()->where('tenant.id', $this->tenant->id)->count(),
            'must not create a duplicate tenant membership'
        );

        // The unit_user link IS created for an already-consenting tenant member.
        $unit = Unit::where('number', '302')->where('tenant_id', tenant('id'))->firstOrFail();
        $this->assertTrue(
            UnitUser::where('unit_id', $unit->id)->where('user_id', $existingUser->id)->exists(),
            'an already-consenting tenant member must still be linked to the unit'
        );

        // No invitation is created for a user who is already a member.
        $this->assertFalse(
            UserInvitation::where('email', $existingUser->email)->where('tenant_id', tenant('id'))->exists(),
            'no invitation should be created for an already-consenting tenant member'
        );

        // Re-import (e.g. the admin re-uploads the file): still idempotent.
        $resolver2 = $this->freshResolver();
        $row2 = $this->validRow(['email' => $existingUser->email, 'numero' => '302', 'fecha_pago' => '2024-03-16']);
        $result2 = $this->importer->execute($row2, 3, $resolver2);

        $this->assertTrue($result2->paymentRegistered);
        $this->assertSame(
            1,
            $existingUser->tenants()->where('tenant.id', $this->tenant->id)->count(),
            'no duplicate membership after re-import'
        );
        $this->assertSame(
            1,
            UnitUser::where('unit_id', $unit->id)->where('user_id', $existingUser->id)->count(),
            'no duplicate unit_user link after re-import'
        );
    }

    // =========================================================================
    // Sub-case M — plan 056: a brand-new email still creates + attaches + profiles
    // the resident exactly as before (regression guard for the untouched create path).
    // =========================================================================

    public function test_m_fresh_email_still_creates_attaches_and_profiles_resident(): void
    {
        // Lowercase on purpose: resolveOrCreateResident()'s create path stores the
        // row's email lowercased (pre-existing, unrelated to plan 056), and
        // Str::random() yields mixed case — an uppercase letter here would make this
        // fixture re-query miss the stored row over a casing difference that has
        // nothing to do with what this test is actually verifying.
        $email = mb_strtolower('nuevo.residente.'.Str::random(8).'@example.com');

        $row = $this->validRow([
            'email' => $email,
            'numero' => '303',
            'rfc' => 'XAXX010101000',
            'curp' => 'XAXX010101HDFXXX01',
            'fecha_nacimiento' => '1990-05-20',
        ]);
        $resolver = $this->freshResolver();

        $result = $this->importer->execute($row, 2, $resolver);

        $this->assertTrue($result->paymentRegistered);
        $this->assertTrue($result->residentCreated, 'a brand-new email must still create a resident');

        $newUser = User::withoutGlobalScope(UserTenantScope::class)->where('email', $email)->first();
        $this->assertNotNull($newUser, 'the new user must be created');
        $this->assertFalse((bool) $newUser->auth_active, 'import-created users start inactive until they set a password');

        $this->assertTrue(
            $newUser->tenants()->where('tenant.id', $this->tenant->id)->exists(),
            'a fresh account must be attached to the importing tenant'
        );

        $unit = Unit::where('number', '303')->where('tenant_id', tenant('id'))->firstOrFail();
        $this->assertTrue(
            UnitUser::where('unit_id', $unit->id)->where('user_id', $newUser->id)->exists(),
            'a fresh account must be linked to the unit immediately (no consent gate for brand-new accounts)'
        );

        $profile = UserProfile::where('user_id', $newUser->id)->first();
        $this->assertNotNull($profile, 'the profile must be created with the row PII');
        $this->assertSame('XAXX010101000', $profile->rfc);
        $this->assertSame('XAXX010101HDFXXX01', $profile->curp);
    }
}
