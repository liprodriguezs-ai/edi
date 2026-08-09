<?php

namespace Modules\DataImport\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\DataImport\Services\ReferenceResolver;
use Modules\DataImport\Services\RowImporter;
use Modules\Housing\Models\UnitType;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Unit tests for RowImporter::validate().
 *
 * Missing mandatory fields produce severity=error.
 * Unregistered catalog values produce severity=warning (NOT error).
 * All-valid rows return an empty issue list.
 */
class RowImporterValidateTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected $connectionsToTransact = [];

    protected Tenant $tenant;

    protected RowImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([TenantCreated::class]);
        $this->tenant = $this->makeTenant();
        tenancy()->initialize($this->tenant);

        $this->importer = new RowImporter(new ReferenceResolver);
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
     * Build a fully-valid row so individual tests can blank out specific fields.
     *
     * @return array<string, mixed>
     */
    private function validRow(): array
    {
        return [
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
        ];
    }

    // =========================================================================
    // Mandatory field errors
    // =========================================================================

    public function test_blank_monto_produces_error_issue(): void
    {
        $row = $this->validRow();
        $row['monto'] = null;

        $issues = $this->importer->validate($row, 3);

        $errorIssues = array_filter($issues, fn ($i) => $i['severity'] === 'error');
        $this->assertNotEmpty($errorIssues);

        $montoError = array_filter($errorIssues, fn ($i) => $i['column'] === 'monto');
        $this->assertNotEmpty($montoError);
        $this->assertEquals(3, reset($montoError)['row']);
    }

    public function test_non_numeric_monto_produces_error_issue(): void
    {
        $row = $this->validRow();
        $row['monto'] = 'abc';

        $issues = $this->importer->validate($row, 5);

        $montoErrors = array_filter($issues, fn ($i) => $i['severity'] === 'error' && $i['column'] === 'monto');
        $this->assertNotEmpty($montoErrors);
    }

    public function test_blank_numero_produces_error_issue(): void
    {
        $row = $this->validRow();
        $row['numero'] = null;

        $issues = $this->importer->validate($row, 7);

        $errors = array_filter($issues, fn ($i) => $i['severity'] === 'error' && $i['column'] === 'numero');
        $this->assertNotEmpty($errors);
    }

    public function test_blank_tipo_unidad_produces_error_issue(): void
    {
        $row = $this->validRow();
        $row['tipo_unidad'] = null;

        $issues = $this->importer->validate($row, 2);

        $errors = array_filter($issues, fn ($i) => $i['severity'] === 'error' && $i['column'] === 'tipo_unidad');
        $this->assertNotEmpty($errors);
    }

    public function test_blank_nombre_produces_error_issue(): void
    {
        $row = $this->validRow();
        $row['nombre'] = null;

        $issues = $this->importer->validate($row, 4);

        $errors = array_filter($issues, fn ($i) => $i['severity'] === 'error' && $i['column'] === 'nombre');
        $this->assertNotEmpty($errors);
    }

    public function test_blank_apellido_paterno_produces_error_issue(): void
    {
        $row = $this->validRow();
        $row['apellido_paterno'] = null;

        $issues = $this->importer->validate($row, 6);

        $errors = array_filter($issues, fn ($i) => $i['severity'] === 'error' && $i['column'] === 'apellido_paterno');
        $this->assertNotEmpty($errors);
    }

    public function test_invalid_fecha_pago_produces_error_issue(): void
    {
        $row = $this->validRow();
        $row['fecha_pago'] = 'not-a-date';

        $issues = $this->importer->validate($row, 8);

        $errors = array_filter($issues, fn ($i) => $i['severity'] === 'error' && $i['column'] === 'fecha_pago');
        $this->assertNotEmpty($errors);
    }

    public function test_blank_fecha_pago_produces_error_issue(): void
    {
        $row = $this->validRow();
        $row['fecha_pago'] = null;

        $issues = $this->importer->validate($row, 9);

        $errors = array_filter($issues, fn ($i) => $i['severity'] === 'error' && $i['column'] === 'fecha_pago');
        $this->assertNotEmpty($errors);
    }

    // =========================================================================
    // Catalog warnings (NOT errors)
    // =========================================================================

    public function test_unregistered_tipo_unidad_produces_warning_not_error(): void
    {
        $row = $this->validRow();
        $row['tipo_unidad'] = 'PH'; // Not in tenant catalog

        $issues = $this->importer->validate($row, 10);

        // Must have at least one warning for tipo_unidad
        $warnings = array_filter($issues, fn ($i) => $i['severity'] === 'warning' && $i['column'] === 'tipo_unidad');
        $this->assertNotEmpty($warnings);

        // Must have NO errors for tipo_unidad
        $errors = array_filter($issues, fn ($i) => $i['severity'] === 'error' && $i['column'] === 'tipo_unidad');
        $this->assertEmpty($errors);
    }

    // =========================================================================
    // Happy path
    // =========================================================================

    public function test_all_mandatory_fields_present_returns_empty_issues_for_registered_type(): void
    {
        // Register the unit type in this tenant
        UnitType::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Casa',
            'order' => 1,
            'is_active' => true,
            'requires_floor' => false,
        ]);

        $row = $this->validRow();
        $issues = $this->importer->validate($row, 1);

        // No errors
        $errors = array_filter($issues, fn ($i) => $i['severity'] === 'error');
        $this->assertEmpty($errors);
    }

    public function test_severity_property_is_always_error_or_warning(): void
    {
        $row = $this->validRow();
        $row['monto'] = null;
        $row['tipo_unidad'] = 'Unknown';

        $issues = $this->importer->validate($row, 2);

        foreach ($issues as $issue) {
            $this->assertContains($issue['severity'], ['error', 'warning']);
            $this->assertArrayHasKey('row', $issue);
            $this->assertArrayHasKey('column', $issue);
            $this->assertArrayHasKey('message', $issue);
        }
    }

    // =========================================================================
    // Money cap (REQ-08) — monto is in PESOS, cap mirrors MoneyLimit / 100
    // =========================================================================

    public function test_monto_at_the_exact_cap_boundary_is_accepted(): void
    {
        $row = $this->validRow();
        $row['monto'] = '9999999.99';

        $issues = $this->importer->validate($row, 11);

        $montoErrors = array_filter($issues, fn ($i) => $i['severity'] === 'error' && $i['column'] === 'monto');
        $this->assertEmpty($montoErrors, 'The exact cap boundary must be accepted');
    }

    public function test_monto_above_the_cap_produces_a_spanish_error(): void
    {
        $row = $this->validRow();
        $row['monto'] = '10000000.00';

        $issues = $this->importer->validate($row, 12);

        $montoErrors = array_values(array_filter(
            $issues,
            fn ($i) => $i['severity'] === 'error' && $i['column'] === 'monto'
        ));

        $this->assertNotEmpty($montoErrors, 'An amount above the cap must be rejected');
        $this->assertEquals('El monto no puede exceder $9,999,999.99.', $montoErrors[0]['message']);
    }

    // =========================================================================
    // Spanish .string messages (REQ-09) — no English fallback leaks
    // =========================================================================

    public function test_non_string_tipo_unidad_produces_spanish_message(): void
    {
        $row = $this->validRow();
        $row['tipo_unidad'] = 123;

        $issues = $this->importer->validate($row, 13);

        $errors = array_values(array_filter($issues, fn ($i) => $i['severity'] === 'error' && $i['column'] === 'tipo_unidad'));
        $this->assertNotEmpty($errors);
        $this->assertEquals('El tipo de unidad debe ser texto.', $errors[0]['message']);
    }

    public function test_non_string_nombre_produces_spanish_message(): void
    {
        $row = $this->validRow();
        $row['nombre'] = 456;

        $issues = $this->importer->validate($row, 14);

        $errors = array_values(array_filter($issues, fn ($i) => $i['severity'] === 'error' && $i['column'] === 'nombre'));
        $this->assertNotEmpty($errors);
        $this->assertEquals('El nombre del residente debe ser texto.', $errors[0]['message']);
    }

    public function test_non_string_apellido_paterno_produces_spanish_message(): void
    {
        $row = $this->validRow();
        $row['apellido_paterno'] = 789;

        $issues = $this->importer->validate($row, 15);

        $errors = array_values(array_filter($issues, fn ($i) => $i['severity'] === 'error' && $i['column'] === 'apellido_paterno'));
        $this->assertNotEmpty($errors);
        $this->assertEquals('El apellido paterno debe ser texto.', $errors[0]['message']);
    }
}
