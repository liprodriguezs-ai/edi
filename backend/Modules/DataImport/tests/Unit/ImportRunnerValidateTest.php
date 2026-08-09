<?php

namespace Modules\DataImport\Tests\Unit;

use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Modules\DataImport\Services\ImportRunner;
use Modules\DataImport\Services\ReferenceResolver;
use Modules\DataImport\Services\RowImporter;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\SubscriptionPlan\Services\PlanPricingService;
use Modules\SubscriptionPlan\Services\UnitLimitService;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Unit tests for ImportRunner::validate() response assembly.
 *
 * Covers:
 * - Collapsing general (catalog-reference) warnings into one entry + count.
 * - Per-row errors and per-row (specific) warnings stay flat, never collapsed.
 * - Distinct new-entity counters (units_to_create / residents_to_create) —
 *   not the valid-row count.
 */
class ImportRunnerValidateTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected $connectionsToTransact = [];

    protected Tenant $tenant;

    protected ImportRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([TenantCreated::class]);
        $this->tenant = $this->makeTenant();
        tenancy()->initialize($this->tenant);

        $this->runner = new ImportRunner(
            new RowImporter(new ReferenceResolver),
            new UnitLimitService(new PlanPricingService),
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
     * Build a fully-valid row so individual tests can override specific fields.
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
            'estado_unidad' => null,
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
            'monto' => '1500.00',
            'metodo_pago' => null,
            'cuenta_destino' => null,
            'referencia' => null,
            'folio' => null,
            'notas' => null,
        ], $overrides);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows  0-based list; re-keyed to 1-based row numbers.
     */
    private function toRowCollection(array $rows): Collection
    {
        $keyed = [];
        foreach ($rows as $index => $row) {
            $keyed[$index + 1] = $row;
        }

        return collect($keyed);
    }

    // =========================================================================
    // Collapse — general (catalog-reference) issues
    // =========================================================================

    public function test_1000_rows_referencing_the_same_unregistered_account_collapse_to_one_issue(): void
    {
        UnitType::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Casa',
            'order' => 1,
            'is_active' => true,
            'requires_floor' => false,
        ]);

        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = $this->validRow([
                'numero' => (string) $i,
                'cuenta_destino' => 'Cuenta Fantasma',
            ]);
        }

        $result = $this->runner->validate($this->toRowCollection($rows));

        $accountIssues = array_filter(
            $result['rows'],
            fn ($issue) => $issue['column'] === 'cuenta_destino' && $issue['severity'] === 'warning'
        );

        $this->assertCount(1, $accountIssues, 'The 1000 identical account warnings must collapse into exactly one issue');
        $accountIssue = reset($accountIssues);
        $this->assertTrue($accountIssue['general']);
        $this->assertEquals(1000, $accountIssue['count']);
    }

    public function test_3_rows_missing_fecha_pago_stay_as_3_separate_error_entries(): void
    {
        $rows = [
            $this->validRow(['numero' => '1', 'fecha_pago' => null]),
            $this->validRow(['numero' => '2']),
            $this->validRow(['numero' => '3', 'fecha_pago' => null]),
            $this->validRow(['numero' => '4', 'fecha_pago' => null]),
        ];

        $result = $this->runner->validate($this->toRowCollection($rows));

        $fechaErrors = array_filter(
            $result['rows'],
            fn ($issue) => $issue['column'] === 'fecha_pago' && $issue['severity'] === 'error'
        );

        $this->assertCount(3, $fechaErrors, 'Each missing-mandatory-field row must produce its own error entry');
        foreach ($fechaErrors as $issue) {
            $this->assertArrayNotHasKey('count', $issue);
        }
    }

    public function test_cross_tenant_email_warning_stays_per_row_not_collapsed(): void
    {
        UnitType::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Casa',
            'order' => 1,
            'is_active' => true,
            'requires_floor' => false,
        ]);

        // Two REAL global users who never joined this tenant — triggers the
        // consent-gated cross-tenant warning in RowImporter::validate().
        User::create(['name' => 'Ana', 'last_name' => 'Gómez', 'email' => 'ana@otro-cluster.com', 'auth_active' => false]);
        User::create(['name' => 'Beto', 'last_name' => 'Ruiz', 'email' => 'beto@otro-cluster.com', 'auth_active' => false]);

        $rows = [
            $this->validRow(['numero' => '1', 'email' => 'ana@otro-cluster.com']),
            $this->validRow(['numero' => '2', 'email' => 'beto@otro-cluster.com']),
        ];

        $result = $this->runner->validate($this->toRowCollection($rows));

        $emailWarnings = array_filter(
            $result['rows'],
            fn ($issue) => $issue['column'] === 'email' && $issue['severity'] === 'warning'
        );

        $this->assertCount(2, $emailWarnings, 'Per-row email cross-tenant warnings must never be collapsed');
        foreach ($emailWarnings as $issue) {
            $this->assertArrayNotHasKey('count', $issue);
        }
    }

    // =========================================================================
    // 422-path error shape (regression guard — REQ-05)
    // =========================================================================

    public function test_error_issues_stay_flat_and_keyable_as_rows_n_column(): void
    {
        $rows = [
            $this->validRow(['numero' => '1']),
            $this->validRow(['numero' => '2', 'monto' => null]),
            $this->validRow(['numero' => '3', 'fecha_pago' => null]),
        ];

        $result = $this->runner->validate($this->toRowCollection($rows));

        $errors = array_filter($result['rows'], fn ($issue) => $issue['severity'] === 'error');
        $this->assertNotEmpty($errors);

        foreach ($errors as $issue) {
            $key = "rows.{$issue['row']}.{$issue['column']}";
            $this->assertMatchesRegularExpression('/^rows\.\d+\.[a-z_]+$/', $key);
        }
    }

    // =========================================================================
    // Distinct counters (REQ-07)
    // =========================================================================

    public function test_1000_rows_across_50_units_only_3_new_reports_units_to_create_3(): void
    {
        $unitType = UnitType::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Casa',
            'order' => 1,
            'is_active' => true,
            'requires_floor' => false,
        ]);

        $unitStatus = UnitStatus::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Activa',
            'order' => 1,
            'is_active' => true,
            'is_billable' => true,
        ]);

        // 47 of the 50 units already exist in the tenant.
        for ($unitNumber = 1; $unitNumber <= 47; $unitNumber++) {
            Unit::create([
                'tenant_id' => $this->tenant->id,
                'unit_type_id' => $unitType->id,
                'unit_status_id' => $unitStatus->id,
                'number' => (string) $unitNumber,
            ]);
        }

        $rows = [];
        for ($unitNumber = 1; $unitNumber <= 50; $unitNumber++) {
            for ($payment = 1; $payment <= 20; $payment++) {
                $rows[] = $this->validRow([
                    'numero' => (string) $unitNumber,
                    'nombre' => "Residente{$unitNumber}",
                    'apellido_paterno' => "Apellido{$unitNumber}",
                ]);
            }
        }

        $this->assertCount(1000, $rows);

        $result = $this->runner->validate($this->toRowCollection($rows));

        $this->assertEquals(1000, $result['total']);
        $this->assertEquals(0, $result['blocked']);
        $this->assertEquals(3, $result['units_to_create'], 'Only units 48, 49, 50 do not yet exist');
        $this->assertEquals(1000, $result['payments_to_register']);
    }

    public function test_existing_unit_is_not_counted_as_new(): void
    {
        $unitType = UnitType::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Casa',
            'order' => 1,
            'is_active' => true,
            'requires_floor' => false,
        ]);

        $unitStatus = UnitStatus::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Activa',
            'order' => 1,
            'is_active' => true,
            'is_billable' => true,
        ]);

        Unit::create([
            'tenant_id' => $this->tenant->id,
            'unit_type_id' => $unitType->id,
            'unit_status_id' => $unitStatus->id,
            'number' => '101',
        ]);

        $rows = [$this->validRow(['numero' => '101'])];

        $result = $this->runner->validate($this->toRowCollection($rows));

        $this->assertEquals(0, $result['units_to_create'], 'A row matching an existing unit must not be counted as new');
    }

    // =========================================================================
    // Resident two-step cascade (W1 regression — REQ-07)
    //
    // RowImporter::execute() resolves a resident via a TWO-STEP CASCADE
    // (RowImporter.php:452-484): email lookup first, then — REGARDLESS of
    // whether the row has an email — a fallback by (unit + nombre +
    // apellido_paterno) via unit_user links. These tests pin
    // calculateDistinctCounters() to that exact cascade.
    // =========================================================================

    /**
     * Create an existing unit with a linked resident (optionally with an
     * account email) so tests can exercise the name-fallback cascade.
     *
     * @return array{0: Unit, 1: User}
     */
    private function createUnitWithLinkedResident(string $number, string $nombre, string $apellidoPaterno, ?string $email = null): array
    {
        // UnitRelationship is a CENTRAL catalog (fixed UUID constants) — seed
        // it once so the unit_relationship_id FK on unit_user resolves.
        (new CatalogSeeder)->run();

        $unitType = UnitType::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'Casa'],
            ['order' => 1, 'is_active' => true, 'requires_floor' => false]
        );

        $unitStatus = UnitStatus::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'Activa'],
            ['order' => 1, 'is_active' => true, 'is_billable' => true]
        );

        $unit = Unit::create([
            'tenant_id' => $this->tenant->id,
            'unit_type_id' => $unitType->id,
            'unit_status_id' => $unitStatus->id,
            'number' => $number,
        ]);

        $user = User::create([
            'name' => $nombre,
            'last_name' => $apellidoPaterno,
            'email' => $email,
            'auth_active' => false,
        ]);

        UnitUser::create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'unit_relationship_id' => UnitRelationship::OWNER,
            'is_billing_owner' => true,
        ]);

        return [$unit, $user];
    }

    public function test_email_row_that_misses_falls_through_to_name_match_and_is_not_new(): void
    {
        // W1 repro: existing unit + linked resident "Juan Pérez" (no email on
        // the account) + an import row with an email that does not match any
        // account. execute() would find Juan via the name fallback — the
        // preview must agree: 0 new residents.
        $this->createUnitWithLinkedResident('101', 'Juan', 'Pérez');

        $rows = [$this->validRow([
            'numero' => '101',
            'nombre' => 'Juan',
            'apellido_paterno' => 'Pérez',
            'email' => 'juan@nuevo.com',
        ])];

        $result = $this->runner->validate($this->toRowCollection($rows));

        $this->assertEquals(0, $result['residents_to_create'], 'An email that misses must fall through to the unit+name fallback, same as a no-email row');
    }

    public function test_email_row_with_a_matching_account_email_is_not_new(): void
    {
        $this->createUnitWithLinkedResident('101', 'Juan', 'Pérez', 'juan@existente.com');

        $rows = [$this->validRow([
            'numero' => '101',
            'nombre' => 'Juan',
            'apellido_paterno' => 'Pérez',
            'email' => 'juan@existente.com',
        ])];

        $result = $this->runner->validate($this->toRowCollection($rows));

        $this->assertEquals(0, $result['residents_to_create'], 'A matching account email must resolve the resident directly, never as new');
    }

    public function test_genuinely_different_person_on_existing_unit_is_counted_as_new(): void
    {
        // Neither the name nor the email matches any linked resident.
        $this->createUnitWithLinkedResident('101', 'Juan', 'Pérez');

        $rows = [$this->validRow([
            'numero' => '101',
            'nombre' => 'María',
            'apellido_paterno' => 'López',
            'email' => 'maria@nueva.com',
        ])];

        $result = $this->runner->validate($this->toRowCollection($rows));

        $this->assertEquals(1, $result['residents_to_create'], 'A genuinely different person (name AND email both miss) must be counted as new');
    }

    public function test_resident_on_a_new_unit_is_always_new_regardless_of_email(): void
    {
        UnitType::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Casa',
            'order' => 1,
            'is_active' => true,
            'requires_floor' => false,
        ]);

        // Unit 999 does not exist yet — a resident on it can never match an
        // existing unit_user link, regardless of whether the row has an email.
        $rows = [$this->validRow([
            'numero' => '999',
            'nombre' => 'Ana',
            'apellido_paterno' => 'Ruiz',
            'email' => 'ana@nueva.com',
        ])];

        $result = $this->runner->validate($this->toRowCollection($rows));

        $this->assertEquals(1, $result['residents_to_create'], 'A resident on a not-yet-created unit is always new, regardless of email');
    }

    public function test_same_person_across_multiple_rows_on_the_same_unit_counts_once(): void
    {
        // Genuinely new person (no match on the existing unit), referenced
        // across two rows: one with no email, one with an email that misses.
        // Both fall through to the same unit+name fallback key and must dedup.
        $this->createUnitWithLinkedResident('101', 'Juan', 'Pérez');

        $rows = [
            $this->validRow([
                'numero' => '101',
                'nombre' => 'María',
                'apellido_paterno' => 'López',
                'email' => null,
            ]),
            $this->validRow([
                'numero' => '101',
                'nombre' => 'María',
                'apellido_paterno' => 'López',
                'email' => 'maria@nueva.com',
            ]),
        ];

        $result = $this->runner->validate($this->toRowCollection($rows));

        $this->assertEquals(1, $result['residents_to_create'], 'The same person on the same unit across multiple rows must count once');
    }
}
