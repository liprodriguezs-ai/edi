<?php

declare(strict_types=1);

namespace Tests\Feature\Board;

use App\Models\User;
use Cknow\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Collaborator\Models\Collaborator;
use Modules\Collaborator\Models\EmployeePosition;
use Modules\Collaborator\Models\EmployeeShift;
use Modules\Collaborator\Models\PayFrequency;
use Modules\Collaborator\Services\EmployeeService;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Integration tests for Module 3 — Employee (Personal Operativo).
 *
 * Covers:
 *  - Employee catalog seeding (employee_position, pay_frequency, employee_shift)
 *  - Termination sets is_active=false + keeps the row (history preserved)
 *  - Reactivation creates a NEW record (not reverting)
 *  - base_salary round-trips as minor units via MoneyIntegerCast
 *  - Tenant isolation
 */
final class EmployeeModuleTest extends TestCase
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

    private function makeCentralUser(): User
    {
        return User::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Employee User',
            'email' => Str::random(8).'@test.com',
            'password' => bcrypt('password'),
        ]);
    }

    // =========================================================================
    // CATALOG SEEDING
    // =========================================================================

    public function test_fresh_tenant_has_six_employee_positions(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $this->assertSame(6, EmployeePosition::count());
            $ids = EmployeePosition::orderBy('order')->pluck('id')->all();
            $this->assertSame(
                [
                    Catalog::id(EmployeePosition::class, 'Vigilante'),
                    Catalog::id(EmployeePosition::class, 'Jardinero'),
                    Catalog::id(EmployeePosition::class, 'Limpieza'),
                    Catalog::id(EmployeePosition::class, 'Mantenimiento'),
                    Catalog::id(EmployeePosition::class, 'Administrador'),
                    Catalog::id(EmployeePosition::class, 'Otro'),
                ],
                $ids
            );
        });
    }

    public function test_fresh_tenant_has_three_pay_frequencies(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $this->assertSame(3, PayFrequency::count());
            $ids = PayFrequency::orderBy('order')->pluck('id')->all();
            $this->assertSame([Catalog::id(PayFrequency::class, 'Quincenal'), Catalog::id(PayFrequency::class, 'Mensual'), Catalog::id(PayFrequency::class, 'Semanal')], $ids);
        });
    }

    public function test_fresh_tenant_has_three_employee_shifts(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $this->assertSame(3, EmployeeShift::count());
            $ids = EmployeeShift::orderBy('order')->pluck('id')->all();
            $this->assertSame([Catalog::id(EmployeeShift::class, 'Diurno'), Catalog::id(EmployeeShift::class, 'Nocturno'), Catalog::id(EmployeeShift::class, 'Mixto')], $ids);
        });
    }

    // =========================================================================
    // BASE SALARY MONEY ROUND-TRIP
    // =========================================================================

    public function test_base_salary_stored_as_minor_units_and_cast_correctly(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeCentralUser();

        $tenant->run(function () use ($user): void {
            // Store 1500000 minor units = $15,000.00 MXN
            $employee = Collaborator::create([
                'user_id' => $user->id,
                'name' => 'Test',
                'last_name' => 'Employee',
                'email' => 'temployee@test.com',
                'phone' => '5551234567',
                'base_salary' => 1500000,
                'is_active' => true,
            ]);

            // Assert the raw DB value is an integer
            $rawValue = DB::table('collaborator')
                ->where('id', $employee->id)
                ->value('base_salary');
            $this->assertSame(1500000, (int) $rawValue, 'base_salary must be stored as integer minor units');

            // Assert the cast returns a Money object
            $fresh = Collaborator::find($employee->id);
            $this->assertInstanceOf(Money::class, $fresh->base_salary);
            $this->assertSame('1500000', $fresh->base_salary->getAmount(), 'Cast should read back 1500000 minor units');
        });
    }

    // =========================================================================
    // TERMINATION — HISTORY PRESERVED
    // =========================================================================

    public function test_terminate_sets_is_active_false_and_preserves_row(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeCentralUser();

        $employeeId = null;

        $tenant->run(function () use ($user, &$employeeId): void {
            $employee = Collaborator::create([
                'user_id' => $user->id,
                'name' => 'Venancio',
                'last_name' => 'Pérez',
                'email' => 'venancio@test.com',
                'phone' => '5559990001',
                'is_active' => true,
                'hire_date' => '2024-01-01',
            ]);

            $employeeId = $employee->id;

            $service = app(EmployeeService::class);
            $service->terminate($employee, '2025-05-31', 'Renuncia voluntaria');
        });

        $tenant->run(function () use ($employeeId): void {
            // Row must still exist (no soft delete / hard delete)
            $employee = Collaborator::withTrashed()->find($employeeId);
            $this->assertNotNull($employee, 'Employee row must be preserved after termination');

            // is_active must be false
            $this->assertFalse((bool) $employee->is_active, 'is_active must be false after termination');

            // termination_date must be recorded
            $this->assertSame('2025-05-31', $employee->termination_date->format('Y-m-d'));

            // termination_reason must be recorded
            $this->assertStringContainsString('Renuncia voluntaria', $employee->termination_reason);
        });
    }

    public function test_terminate_already_terminated_employee_throws(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeCentralUser();

        $this->expectException(\InvalidArgumentException::class);

        $tenant->run(function () use ($user): void {
            $employee = Collaborator::create([
                'user_id' => $user->id,
                'name' => 'Already',
                'last_name' => 'Gone',
                'is_active' => false,
                'termination_date' => '2024-12-31',
                'termination_reason' => 'Previous termination',
            ]);

            app(EmployeeService::class)->terminate($employee, '2025-05-31', 'Motivo 2');
        });
    }

    // =========================================================================
    // REACTIVATION — NEW RECORD
    // =========================================================================

    public function test_reactivate_creates_new_collaborator_record(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeCentralUser();

        $tenant->run(function () use ($user): void {
            $employee = Collaborator::create([
                'user_id' => $user->id,
                'name' => 'Maria',
                'last_name' => 'Lopez',
                'email' => 'maria@test.com',
                'phone' => '5559990002',
                'is_active' => true,
            ]);

            // Terminate first
            $service = app(EmployeeService::class);
            $service->terminate($employee, '2025-01-31', 'Término de contrato');

            $countBefore = Collaborator::withTrashed()->count();

            // Reactivate
            $newEmployee = $service->reactivate($employee, ['hire_date' => '2025-06-01']);

            $countAfter = Collaborator::withTrashed()->count();

            // A NEW row must have been created
            $this->assertSame($countBefore + 1, $countAfter, 'Reactivation must create a NEW employee record');

            // New record is active
            $this->assertTrue((bool) $newEmployee->is_active);
            $this->assertNull($newEmployee->termination_date);

            // Old record remains terminated
            $employee->refresh();
            $this->assertFalse((bool) $employee->is_active);
        });
    }

    public function test_reactivate_active_employee_throws(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeCentralUser();

        $this->expectException(\InvalidArgumentException::class);

        $tenant->run(function () use ($user): void {
            $employee = Collaborator::create([
                'user_id' => $user->id,
                'name' => 'Active',
                'last_name' => 'Worker',
                'is_active' => true,
            ]);

            app(EmployeeService::class)->reactivate($employee, []);
        });
    }

    // =========================================================================
    // CATÁLOGOS AUTOADMINISTRABLES — COPIA POR TENANT (by design)
    // =========================================================================

    public function test_employee_catalogs_are_tenant_scoped_copies(): void
    {
        // employee_position es un catálogo AUTOADMINISTRABLE (tenant-scoped):
        // cada tenant recibe su copia sembrada con grants_role en las filas
        // que otorgan rol Kivo7 al dar de alta empleados.
        $tenant1 = $this->makeTenant('Fraccionamiento X');
        $tenant2 = $this->makeTenant('Fraccionamiento Y');
        $this->seedTenant($tenant1);
        $this->seedTenant($tenant2);

        $tenant1->run(function (): void {
            $this->assertSame(6, EmployeePosition::count(), 'Tenant 1 ve SU copia');
            $this->assertSame(
                'security',
                EmployeePosition::query()->where('name', 'Vigilante')->value('grants_role'),
            );
            $this->assertSame(
                'administrator',
                EmployeePosition::query()->where('name', 'Administrador')->value('grants_role'),
            );
        });

        $tenant2->run(function (): void {
            $this->assertSame(6, EmployeePosition::count(), 'Tenant 2 ve SU copia');
        });

        // La tabla acumula una copia POR tenant — nunca filas compartidas.
        $total = DB::table('employee_position')
            ->whereNull('deleted_at')
            ->whereIn('tenant_id', [$tenant1->getKey(), $tenant2->getKey()])
            ->count();
        $this->assertSame(12, $total, 'Una copia de 6 filas por tenant');
    }

    // =========================================================================
    // FIX A4 — termination revokes app access (spec §7.4.4)
    // =========================================================================

    public function test_terminate_with_revoke_app_access_blocks_login_and_drops_tokens(): void
    {
        $tenant = $this->makeTenant('Revoke Access Tenant');
        $this->seedTenant($tenant);

        $email = Str::random(8).'@encinos.mx';
        $user = User::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Vigilante Activo',
            'email' => $email,
            'password' => bcrypt('secret1234'),
            'auth_active' => true,
        ]);
        $user->tenants()->attach($tenant->id);
        $user->update(['last_active_tenant_id' => $tenant->id]);

        // The user has at least one active token before termination.
        $user->createToken('mobile', ['*']);
        $this->assertSame(1, $user->tokens()->count());

        $tenant->run(function () use ($user): void {
            $employee = Collaborator::create([
                'user_id' => $user->id,
                'name' => 'Vigilante',
                'last_name' => 'Activo',
                'is_active' => true,
                'hire_date' => '2025-01-01',
            ]);

            app(EmployeeService::class)->terminate(
                $employee,
                '2026-05-31',
                'Fin de contrato',
                null,
                true, // revoke_app_access
            );
        });

        // auth_active flipped to false and ALL tokens dropped.
        $fresh = User::withoutGlobalScopes()->find($user->id);
        $this->assertFalse((bool) $fresh->auth_active, 'auth_active must be false after revocation');
        $this->assertSame(0, $fresh->tokens()->count(), 'All tokens must be deleted on revocation');

        // A subsequent real login attempt is rejected with 403 (Cuenta desactivada).
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'secret1234'])
            ->assertStatus(403);
    }

    public function test_terminate_without_revoke_keeps_user_active(): void
    {
        $tenant = $this->makeTenant('Keep Active Tenant');
        $this->seedTenant($tenant);

        $user = User::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Jardinero',
            'email' => Str::random(8).'@encinos.mx',
            'password' => bcrypt('secret1234'),
            'auth_active' => true,
        ]);
        $user->createToken('mobile', ['*']);

        $tenant->run(function () use ($user): void {
            $employee = Collaborator::create([
                'user_id' => $user->id,
                'name' => 'Jardinero',
                'last_name' => 'García',
                'is_active' => true,
                'hire_date' => '2025-01-01',
            ]);

            app(EmployeeService::class)->terminate(
                $employee,
                '2026-05-31',
                'Reubicación',
                null,
                false, // do NOT revoke app access
            );
        });

        $fresh = User::withoutGlobalScopes()->find($user->id);
        $this->assertTrue((bool) $fresh->auth_active, 'User stays active when revoke_app_access is false');
        $this->assertSame(1, $fresh->tokens()->count(), 'Tokens are preserved when access is not revoked');
    }
}
