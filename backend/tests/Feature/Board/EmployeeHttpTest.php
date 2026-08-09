<?php

declare(strict_types=1);

namespace Tests\Feature\Board;

use App\Models\Role as AppRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Collaborator\Models\Collaborator;
use Modules\Collaborator\Models\EmployeePosition;
use Modules\Collaborator\Models\EmployeeShift;
use Modules\Collaborator\Models\PayFrequency;
use Modules\Collaborator\Services\EmployeeService;
use Modules\Email\Emails\UserInvitationMail;
use Modules\Tenant\Models\Tenant;
use Modules\User\Models\UserInvitation;
use Modules\User\Services\UserInvitationService;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * HTTP tests for the Employee (Collaborator) module — board-staff-19.
 *
 * Covers the previously-untested API surface:
 *  - POST /collaborator persists the §7.4.2 labor data (board-staff-10)
 *  - base_salary > 0 and phone-vs-user uniqueness validations (board-staff-11)
 *  - terminate/reactivate/import/resend authz matrix §7.2 (board-staff-06)
 *  - app access carries the Kivo7 role mapped from the position (board-staff-12)
 */
final class EmployeeHttpTest extends TestCase
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

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeUser(Tenant $tenant, ?string $phone = null): User
    {
        $user = User::create([
            'name' => 'Employee Http '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'phone' => $phone,
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        return $user;
    }

    private function adminToken(User $user): string
    {
        return $user->createToken('admin', ['*'])->plainTextToken;
    }

    /**
     * @param  array<string>  $abilities
     */
    private function limitedToken(User $user, array $abilities): string
    {
        return $user->createToken('limited', $abilities)->plainTextToken;
    }

    private function makeActiveEmployee(Tenant $tenant, ?string $userId = null): Collaborator
    {
        return $tenant->run(static fn (): Collaborator => Collaborator::create([
            'user_id' => $userId,
            'name' => 'Empleado '.Str::random(4),
            'last_name' => 'Prueba',
            'is_active' => true,
            'hire_date' => '2025-01-01',
        ]));
    }

    // =========================================================================
    // board-staff-10 — POST /collaborator persists the §7.4.2 labor data
    // =========================================================================

    public function test_create_employee_persists_position_salary_frequency_shift_work_days_and_hire_date(): void
    {
        $tenant = $this->makeTenant('Employee Data Tenant');
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);

        $employeeId = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/collaborator', [
                'name' => 'José Luis',
                'last_name' => 'Hernández',
                'phone' => '5598765432',
                'employee_position_id' => Catalog::id(EmployeePosition::class, 'Jardinero'),
                'pay_frequency_id' => Catalog::id(PayFrequency::class, 'Mensual'),
                'employee_shift_id' => Catalog::id(EmployeeShift::class, 'Diurno'),
                'base_salary' => 850000,
                'work_days' => ['monday', 'wednesday', 'friday'],
                'hire_date' => '2026-06-01',
            ])
            ->assertStatus(201)
            ->json('data.id');

        $tenant->run(function () use ($employeeId): void {
            $employee = Collaborator::findOrFail($employeeId);

            $this->assertSame(Catalog::id(EmployeePosition::class, 'Jardinero'), $employee->employee_position_id);
            $this->assertSame(Catalog::id(PayFrequency::class, 'Mensual'), $employee->pay_frequency_id);
            $this->assertSame(Catalog::id(EmployeeShift::class, 'Diurno'), $employee->employee_shift_id);
            $this->assertSame(['monday', 'wednesday', 'friday'], $employee->work_days);
            $this->assertSame('2026-06-01', $employee->hire_date->format('Y-m-d'));

            $rawSalary = DB::table('collaborator')->where('id', $employeeId)->value('base_salary');
            $this->assertSame(850000, (int) $rawSalary, 'base_salary must persist as integer minor units');
        });
    }

    public function test_update_employee_persists_labor_data(): void
    {
        $tenant = $this->makeTenant('Employee Update Tenant');
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $employee = $this->makeActiveEmployee($tenant);

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->patchJson("/api/collaborator/{$employee->id}", [
                'employee_position_id' => Catalog::id(EmployeePosition::class, 'Vigilante'),
                'base_salary' => 920000,
            ])
            ->assertStatus(200);

        $tenant->run(function () use ($employee): void {
            $fresh = Collaborator::findOrFail($employee->id);
            $this->assertSame(Catalog::id(EmployeePosition::class, 'Vigilante'), $fresh->employee_position_id);
            $this->assertSame(920000, (int) DB::table('collaborator')->where('id', $employee->id)->value('base_salary'));
        });
    }

    // =========================================================================
    // board-staff-11 — salary > 0 and phone not duplicated against user
    // =========================================================================

    public function test_create_employee_rejects_zero_or_invalid_salary(): void
    {
        $tenant = $this->makeTenant('Salary Validation Tenant');
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $token = $this->adminToken($admin);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/collaborator', [
                'name' => 'Salario',
                'last_name' => 'Cero',
                'base_salary' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['base_salary']);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/collaborator', [
                'name' => 'Salario',
                'last_name' => 'Negativo',
                'base_salary' => -100,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['base_salary']);
    }

    public function test_create_employee_rejects_phone_duplicated_against_central_user(): void
    {
        $tenant = $this->makeTenant('Phone Validation Tenant');
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $token = $this->adminToken($admin);

        $existingUser = $this->makeUser($tenant, '5511224488');

        // Phone of an UNRELATED central user → rejected (§7.4.2 validaciones).
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/collaborator', [
                'name' => 'Telefono',
                'last_name' => 'Duplicado',
                'phone' => '5511224488',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        // Same phone is allowed when the employee is linked to THAT user.
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/collaborator', [
                'name' => 'Telefono',
                'last_name' => 'Propio',
                'user_id' => $existingUser->id,
                'phone' => '5511224488',
            ])
            ->assertStatus(201);
    }

    public function test_reactivate_rejects_zero_salary(): void
    {
        $tenant = $this->makeTenant('Reactivate Salary Tenant');
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $employee = $this->makeActiveEmployee($tenant);

        $tenant->run(function () use ($employee): void {
            app(EmployeeService::class)->terminate($employee, '2026-05-31', 'Fin de contrato');
        });

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson("/api/collaborator/{$employee->id}/reactivate", ['base_salary' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['base_salary']);
    }

    // =========================================================================
    // board-staff-06 — authz matrix §7.2 for the custom employee endpoints
    // =========================================================================

    public function test_resident_cannot_terminate_reactivate_or_import(): void
    {
        $tenant = $this->makeTenant('Resident Authz Tenant');
        $this->seedTenant($tenant);

        $resident = $this->makeUser($tenant);
        $token = $this->limitedToken($resident, ['read.dashboard']);
        $employee = $this->makeActiveEmployee($tenant);

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/collaborator/{$employee->id}/terminate", [
                'termination_date' => '2026-06-01',
                'termination_reason' => 'Intento no autorizado',
            ])
            ->assertStatus(403);

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/collaborator/{$employee->id}/reactivate", [])
            ->assertStatus(403);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/collaborator/batch_import', ['rows' => [['name' => 'X', 'last_name' => 'Y']]])
            ->assertStatus(403);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/collaborator/validate_import', ['rows' => [['name' => 'X', 'last_name' => 'Y']]])
            ->assertStatus(403);

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/collaborator/{$employee->id}/resend_invitation")
            ->assertStatus(403);

        // Side-effect free: the employee is still active.
        $tenant->run(function () use ($employee): void {
            $this->assertTrue((bool) Collaborator::findOrFail($employee->id)->is_active);
        });
    }

    public function test_treasurer_with_read_only_collaborator_cannot_terminate(): void
    {
        $tenant = $this->makeTenant('Treasurer Authz Tenant');
        $this->seedTenant($tenant);

        $treasurer = $this->makeUser($tenant);
        // §7.2: treasurer sees the staff directory (read) but cannot hire/fire.
        $token = $this->limitedToken($treasurer, ['read.collaborator']);
        $employee = $this->makeActiveEmployee($tenant);

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/collaborator')
            ->assertStatus(200);

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/collaborator/{$employee->id}/terminate", [
                'termination_date' => '2026-06-01',
                'termination_reason' => 'Tesorero sin permiso',
            ])
            ->assertStatus(403);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/collaborator/batch_import', ['rows' => [['name' => 'X', 'last_name' => 'Y']]])
            ->assertStatus(403);
    }

    public function test_admin_can_terminate_and_reactivate(): void
    {
        $tenant = $this->makeTenant('Admin Authz Tenant');
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $token = $this->adminToken($admin);
        $employee = $this->makeActiveEmployee($tenant);

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/collaborator/{$employee->id}/terminate", [
                'termination_date' => '2026-06-01',
                'termination_reason' => 'Renuncia voluntaria',
            ])
            ->assertStatus(200);

        $tenant->run(function () use ($employee): void {
            $this->assertFalse((bool) Collaborator::findOrFail($employee->id)->is_active);
        });

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/collaborator/{$employee->id}/reactivate", [
                'hire_date' => '2026-06-15',
                'base_salary' => 700000,
            ])
            ->assertStatus(201);
    }

    // =========================================================================
    // board-staff-12 — app access carries the Kivo7 role of the position
    // =========================================================================

    public function test_invitation_acceptance_assigns_position_mapped_vecinia_role(): void
    {
        Mail::fake();

        foreach (['security', 'administrator', 'operations_staff', 'collaborator'] as $slug) {
            AppRole::firstOrCreate(['name' => $slug, 'guard_name' => 'api']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $tenant = $this->makeTenant('Invitation Role Tenant');
        $this->seedTenant($tenant);

        $email = Str::random(8).'@encinos.mx';

        $collaborator = $tenant->run(static fn (): Collaborator => Collaborator::create([
            'name' => 'Pedro',
            'last_name' => 'Vargas',
            'email' => $email,
            'employee_position_id' => Catalog::id(EmployeePosition::class, 'Vigilante'),
            'create_as_user' => true,
            'is_active' => true,
        ]));

        // The plaintext token is no longer persisted (only its SHA-256 hash is);
        // recover it from the invitation email, the way the invitee receives it.
        $plainToken = null;
        // System mailables implement ShouldQueue, so under Mail::fake() they land
        // in the queued bucket — assert there, not on assertSent.
        Mail::assertQueued(UserInvitationMail::class, function (UserInvitationMail $mail) use (&$plainToken): bool {
            $plainToken = Str::afterLast($mail->acceptUrl, '/');

            return true;
        });
        $this->assertNotNull($plainToken, 'The invitation email must carry the plaintext token.');

        $invitation = UserInvitation::query()
            ->where('inviteable_id', $collaborator->id)
            ->firstOrFail();
        $this->assertNull($invitation->token, 'The plaintext token must not be stored.');
        $this->assertNotNull($invitation->token_hash, 'The token hash must be stored.');

        $user = app(UserInvitationService::class)->acceptInvitation($plainToken, 'secret1234');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $fresh = User::withoutGlobalScopes()->findOrFail($user->id);

        $this->assertTrue(
            $fresh->hasRole('security'),
            'A security guard accepting app access must receive the Kivo7 security role'
        );
        $this->assertFalse(
            $fresh->hasRole('collaborator'),
            'The legacy CRM collaborator role must NOT be assigned when the Kivo7 role exists'
        );
    }

    public function test_resolve_vecinia_role_maps_positions_correctly(): void
    {
        foreach (['security', 'administrator', 'operations_staff'] as $slug) {
            AppRole::firstOrCreate(['name' => $slug, 'guard_name' => 'api']);
        }

        $service = app(EmployeeService::class);

        $guard = new Collaborator(['name' => 'G']);
        $guard->employee_position_id = Catalog::id(EmployeePosition::class, 'Vigilante');
        $this->assertSame('security', $service->resolveKivo7Role($guard)?->name);

        $adminEmployee = new Collaborator(['name' => 'A']);
        $adminEmployee->employee_position_id = Catalog::id(EmployeePosition::class, 'Administrador');
        $this->assertSame('administrator', $service->resolveKivo7Role($adminEmployee)?->name);

        $gardener = new Collaborator(['name' => 'J']);
        $gardener->employee_position_id = Catalog::id(EmployeePosition::class, 'Jardinero');
        $this->assertSame('operations_staff', $service->resolveKivo7Role($gardener)?->name);

        $noPosition = new Collaborator(['name' => 'N']);
        $this->assertSame('operations_staff', $service->resolveKivo7Role($noPosition)?->name);
    }
}
