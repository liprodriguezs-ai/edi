<?php

namespace Modules\DataImport\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Collections\Models\PaymentMethod;
use Modules\DataImport\Services\ReferenceResolver;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitType;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Unit tests for ReferenceResolver.
 *
 * Exercises name→ID resolution, warning emission, default fallbacks,
 * and memoization (second call skips DB query).
 */
class ReferenceResolverTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected $connectionsToTransact = [];

    protected Tenant $tenant;

    protected ReferenceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([TenantCreated::class]);
        $this->tenant = $this->makeTenant();
        tenancy()->initialize($this->tenant);
        $this->resolver = new ReferenceResolver;
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    // =========================================================================
    // UnitType
    // =========================================================================

    public function test_unregistered_unit_type_returns_warning_and_null_id(): void
    {
        $result = $this->resolver->resolveUnitType('PH', 5);

        $this->assertNull($result['id']);
        $this->assertCount(1, $result['issues']);
        $this->assertEquals('warning', $result['issues'][0]['severity']);
        $this->assertEquals('tipo_unidad', $result['issues'][0]['column']);
        $this->assertEquals(5, $result['issues'][0]['row']);
        $this->assertTrue($result['issues'][0]['general'], 'Catalog-reference issues must be marked general at creation time');
    }

    public function test_registered_unit_type_returns_id_with_no_issues(): void
    {
        $unitType = UnitType::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Casa',
            'order' => 1,
            'is_active' => true,
            'requires_floor' => false,
        ]);

        $result = $this->resolver->resolveUnitType('Casa', 3);

        $this->assertEquals($unitType->id, $result['id']);
        $this->assertEmpty($result['issues']);
    }

    public function test_unit_type_resolution_is_case_insensitive(): void
    {
        $unitType = UnitType::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Departamento',
            'order' => 1,
            'is_active' => true,
            'requires_floor' => true,
        ]);

        $result = $this->resolver->resolveUnitType('DEPARTAMENTO', 1);

        $this->assertEquals($unitType->id, $result['id']);
        $this->assertEmpty($result['issues']);
    }

    public function test_unit_type_memoization_skips_second_db_query(): void
    {
        // Pre-register the type
        $unitType = UnitType::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Villa',
            'order' => 1,
            'is_active' => true,
            'requires_floor' => false,
        ]);

        // First call — hits DB
        $first = $this->resolver->resolveUnitType('Villa', 1);
        $this->assertEquals($unitType->id, $first['id']);

        // Delete the record from DB to verify second call uses cache
        $unitType->forceDelete();

        // Second call — should use memoized result (no DB query)
        $second = $this->resolver->resolveUnitType('Villa', 2);
        $this->assertEquals($unitType->id, $second['id']);
        $this->assertEmpty($second['issues']);
    }

    // =========================================================================
    // UnitStatus
    // =========================================================================

    public function test_unregistered_unit_status_returns_warning_with_default_fallback_text(): void
    {
        $result = $this->resolver->resolveUnitStatus('Fuera de servicio', 6);

        $this->assertNull($result['id']);
        $this->assertCount(1, $result['issues']);
        $this->assertEquals('warning', $result['issues'][0]['severity']);
        $this->assertEquals('estado_unidad', $result['issues'][0]['column']);
        $this->assertTrue($result['issues'][0]['general']);
        $this->assertStringNotContainsString('sin estado', $result['issues'][0]['message'], 'Must not claim the unit is imported without a status');
        $this->assertStringContainsString('estado por defecto del condominio', $result['issues'][0]['message']);
    }

    // =========================================================================
    // Tower
    // =========================================================================

    public function test_unregistered_tower_returns_warning_and_null_id(): void
    {
        $result = $this->resolver->resolveTower('Torre Fantasma', 2);

        $this->assertNull($result['id']);
        $this->assertCount(1, $result['issues']);
        $this->assertEquals('warning', $result['issues'][0]['severity']);
        $this->assertEquals('torre', $result['issues'][0]['column']);
        $this->assertTrue($result['issues'][0]['general']);
    }

    // =========================================================================
    // Section
    // =========================================================================

    public function test_unregistered_section_returns_warning_and_null_id(): void
    {
        $result = $this->resolver->resolveSection('Sección Fantasma', 8);

        $this->assertNull($result['id']);
        $this->assertCount(1, $result['issues']);
        $this->assertEquals('warning', $result['issues'][0]['severity']);
        $this->assertEquals('seccion', $result['issues'][0]['column']);
        $this->assertTrue($result['issues'][0]['general']);
    }

    // =========================================================================
    // BankAccount
    // =========================================================================

    public function test_unregistered_bank_account_returns_warning_and_null_id(): void
    {
        $result = $this->resolver->resolveBankAccount('Caja 2', 9);

        $this->assertNull($result['id']);
        $this->assertCount(1, $result['issues']);
        $this->assertEquals('warning', $result['issues'][0]['severity']);
        $this->assertEquals('cuenta_destino', $result['issues'][0]['column']);
        $this->assertTrue($result['issues'][0]['general']);
    }

    public function test_registered_bank_account_returns_id_with_no_issues(): void
    {
        $purpose = BankAccountPurpose::where('tenant_id', $this->tenant->id)->first()
            ?? BankAccountPurpose::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'General',
                'order' => 1,
                'is_active' => true,
            ]);

        $account = BankAccount::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Caja Principal',
            'bank_account_type_id' => BankAccountType::CASH,
            'bank_account_purpose_id' => $purpose->id,
            'currency' => 'MXN',
            'initial_balance' => 0,
            'is_primary' => true,
            'is_active' => true,
        ]);

        $result = $this->resolver->resolveBankAccount('Caja Principal', 7);

        $this->assertEquals($account->id, $result['id']);
        $this->assertEmpty($result['issues']);
    }

    // =========================================================================
    // PaymentMethod (CENTRAL — default fallback to CASH)
    // =========================================================================

    public function test_unregistered_payment_method_emits_warning_and_returns_cash_default(): void
    {
        $result = $this->resolver->resolvePaymentMethod('Bitcoins', 12);

        $this->assertEquals(PaymentMethod::CASH, $result['id']);
        $this->assertCount(1, $result['issues']);
        $this->assertEquals('warning', $result['issues'][0]['severity']);
        $this->assertEquals('metodo_pago', $result['issues'][0]['column']);
        $this->assertTrue($result['issues'][0]['general']);
    }

    public function test_blank_payment_method_returns_cash_default_with_no_issue(): void
    {
        $result = $this->resolver->resolvePaymentMethod(null, 3);

        $this->assertEquals(PaymentMethod::CASH, $result['id']);
        $this->assertEmpty($result['issues']);
    }

    // =========================================================================
    // UnitRelationship (CENTRAL — default fallback to OWNER)
    // =========================================================================

    public function test_blank_relation_returns_owner_default_with_no_issue(): void
    {
        $result = $this->resolver->resolveUnitRelationship(null, 3);

        $this->assertEquals(UnitRelationship::OWNER, $result['id']);
        $this->assertEmpty($result['issues']);
    }

    public function test_unregistered_relation_emits_warning_and_returns_owner_default(): void
    {
        $result = $this->resolver->resolveUnitRelationship('Desconocida', 4);

        $this->assertEquals(UnitRelationship::OWNER, $result['id']);
        $this->assertCount(1, $result['issues']);
        $this->assertEquals('warning', $result['issues'][0]['severity']);
        $this->assertEquals('relacion', $result['issues'][0]['column']);
        $this->assertTrue($result['issues'][0]['general']);
    }

    // =========================================================================
    // Null / empty input handling
    // =========================================================================

    public function test_null_unit_type_returns_null_with_no_issues(): void
    {
        $result = $this->resolver->resolveUnitType(null, 1);

        $this->assertNull($result['id']);
        $this->assertEmpty($result['issues']);
    }

    public function test_null_bank_account_returns_null_with_no_issues(): void
    {
        $result = $this->resolver->resolveBankAccount(null, 1);

        $this->assertNull($result['id']);
        $this->assertEmpty($result['issues']);
    }
}
