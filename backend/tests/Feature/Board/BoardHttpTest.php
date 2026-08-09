<?php

declare(strict_types=1);

namespace Tests\Feature\Board;

use App\Models\Role as AppRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Board\Models\BoardMember;
use Modules\Board\Models\BoardPeriod;
use Modules\Board\Models\BoardPeriodStatus;
use Modules\Board\Models\BoardRole;
use Modules\Board\Services\BoardService;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * HTTP tests for the Board module (board-staff-19).
 *
 * Covers the previously-untested API surface:
 *  - Direct board_member CRUD grants/revokes Spatie roles (board-staff-03)
 *  - Raw PATCH cannot flip board_period status/dates (board-staff-04)
 *  - startPeriod rejects users outside the tenant (board-staff-05)
 *  - Auto-close with OVERLAPPING dates + end_date truncation (board-staff-02)
 *  - Acta upload endpoint: PDF only + permission gate (board-staff-16)
 *  - board:close-expired-periods daily command (board-staff-15)
 *  - Catalog search no longer 500s on the removed 'key' column (board-staff-14)
 */
final class BoardHttpTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    /** @var array<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('private');
        Storage::fake(config('media-library.disk_name'));
        $this->seedGlobalBoardRoles();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function seedGlobalBoardRoles(): void
    {
        foreach (['president', 'treasurer', 'secretary', 'vocal', 'oversight_committee'] as $slug) {
            AppRole::firstOrCreate(['name' => $slug, 'guard_name' => 'api']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeUser(Tenant $tenant): User
    {
        $user = User::create([
            'name' => 'Board Http '.Str::random(5),
            'email' => Str::random(10).'@test.com',
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
     * @return array<int, array{user_id: string, board_role_id: string, unit_id: null}>
     */
    private function trio(Tenant $tenant, User $president): array
    {
        return [
            ['user_id' => $president->id, 'board_role_id' => BoardRole::PRESIDENT, 'unit_id' => null],
            ['user_id' => $this->makeUser($tenant)->id, 'board_role_id' => BoardRole::TREASURER, 'unit_id' => null],
            ['user_id' => $this->makeUser($tenant)->id, 'board_role_id' => BoardRole::SECRETARY, 'unit_id' => null],
        ];
    }

    private function makeCurrentPeriod(Tenant $tenant, string $name, string $start, string $end): BoardPeriod
    {
        return $tenant->run(static fn (): BoardPeriod => BoardPeriod::create([
            'name' => $name,
            'start_date' => $start,
            'end_date' => $end,
            'board_period_status_id' => BoardPeriodStatus::CURRENT,
        ]));
    }

    private function fakePdfFile(string $name = 'acta.pdf'): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'vecinia_acta_');
        $this->tempFiles[] = $tmpPath;
        file_put_contents($tmpPath, str_repeat('%PDF-1.4 acta constitutiva de prueba ', 100));

        return new UploadedFile($tmpPath, $name, 'application/pdf', null, true);
    }

    private function fakeJpegFile(string $name = 'foto.jpg'): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'vecinia_jpg_');
        $this->tempFiles[] = $tmpPath;
        file_put_contents($tmpPath, "\xFF\xD8\xFF\xE0".str_repeat('x', 1000));

        return new UploadedFile($tmpPath, $name, 'image/jpeg', null, true);
    }

    private function freshUser(string $id): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return User::withoutGlobalScopes()->findOrFail($id);
    }

    // =========================================================================
    // board-staff-03 — direct board_member CRUD syncs Spatie roles
    // =========================================================================

    public function test_board_member_crud_grants_and_revokes_spatie_roles(): void
    {
        $tenant = $this->makeTenant('Member CRUD Tenant');
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $token = $this->adminToken($admin);
        $userA = $this->makeUser($tenant);
        $userB = $this->makeUser($tenant);

        $period = $this->makeCurrentPeriod($tenant, 'CRUD 2026-2027', '2026-01-01', '2027-12-31');

        // CREATE: the vocal seat grants the 'vocal' Spatie role.
        $memberId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/board_member', [
                'board_period_id' => $period->id,
                'user_id' => $userA->id,
                'board_role_id' => BoardRole::VOCAL,
                'unit_id' => null,
            ])
            ->assertStatus(201)
            ->json('data.id');

        $this->assertTrue($this->freshUser($userA->id)->hasRole('vocal'), 'POST /board_member must grant the mapped Spatie role');

        // UPDATE: moving the seat to another user revokes A and grants B.
        $this->tenantRequest($token, $tenant)
            ->patchJson("/api/board_member/{$memberId}", ['user_id' => $userB->id])
            ->assertStatus(200);

        $this->assertFalse($this->freshUser($userA->id)->hasRole('vocal'), 'Seat reassignment must revoke the previous holder');
        $this->assertTrue($this->freshUser($userB->id)->hasRole('vocal'), 'Seat reassignment must grant the new holder');

        // DELETE: removing the seat revokes the role.
        $this->tenantRequest($token, $tenant)
            ->deleteJson("/api/board_member/{$memberId}")
            ->assertStatus(200);

        $this->assertFalse($this->freshUser($userB->id)->hasRole('vocal'), 'DELETE /board_member must revoke the Spatie role');
    }

    public function test_board_member_create_rejects_user_outside_tenant(): void
    {
        $tenant = $this->makeTenant('Member Cross Tenant');
        $otherTenant = $this->makeTenant('Member Other Tenant');
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $foreignUser = $this->makeUser($otherTenant);

        $period = $this->makeCurrentPeriod($tenant, 'Cross 2026', '2026-01-01', '2026-12-31');

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/board_member', [
                'board_period_id' => $period->id,
                'user_id' => $foreignUser->id,
                'board_role_id' => BoardRole::VOCAL,
                'unit_id' => null,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);

        $this->assertFalse(
            $this->freshUser($foreignUser->id)->hasRole('vocal'),
            'A cross-tenant user must never receive a Spatie role via board_member'
        );
    }

    // =========================================================================
    // board-staff-04 — raw PATCH cannot bypass the period lifecycle
    // =========================================================================

    public function test_raw_patch_cannot_change_period_status_or_dates(): void
    {
        $tenant = $this->makeTenant('Patch Guard Tenant');
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $token = $this->adminToken($admin);
        $period = $this->makeCurrentPeriod($tenant, 'Guard 2026', '2026-01-01', '2026-12-31');

        $this->tenantRequest($token, $tenant)
            ->patchJson("/api/board_period/{$period->id}", ['board_period_status_id' => BoardPeriodStatus::CLOSED])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['board_period_status_id']);

        $this->tenantRequest($token, $tenant)
            ->patchJson("/api/board_period/{$period->id}", ['start_date' => '2020-01-01', 'end_date' => '2030-12-31'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['start_date', 'end_date']);

        // The period is untouched and still CURRENT.
        $tenant->run(function () use ($period): void {
            $fresh = BoardPeriod::findOrFail($period->id);
            $this->assertSame(BoardPeriodStatus::CURRENT, $fresh->board_period_status_id);
            $this->assertSame('2026-01-01', $fresh->start_date->format('Y-m-d'));
        });

        // Positive control: descriptive fields remain editable.
        $this->tenantRequest($token, $tenant)
            ->patchJson("/api/board_period/{$period->id}", ['notes' => 'Notas actualizadas.'])
            ->assertStatus(200);
    }

    // =========================================================================
    // board-staff-05 — startPeriod rejects users outside the tenant
    // =========================================================================

    public function test_start_period_rejects_member_from_another_tenant(): void
    {
        $tenant = $this->makeTenant('Start Cross A');
        $otherTenant = $this->makeTenant('Start Cross B');
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $foreignUser = $this->makeUser($otherTenant);

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/board_period/start', [
                'name' => 'Cross Tenant Attempt',
                'start_date' => '2026-07-01',
                'end_date' => '2027-06-30',
                'auto_close_current' => false,
                'members' => [
                    ['user_id' => $foreignUser->id, 'board_role_id' => BoardRole::PRESIDENT, 'unit_id' => null],
                    ['user_id' => $this->makeUser($tenant)->id, 'board_role_id' => BoardRole::TREASURER, 'unit_id' => null],
                    ['user_id' => $this->makeUser($tenant)->id, 'board_role_id' => BoardRole::SECRETARY, 'unit_id' => null],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['members.0.user_id']);

        $this->assertFalse(
            $this->freshUser($foreignUser->id)->hasRole('president'),
            'startPeriod must never grant a global Spatie role to a user of another tenant'
        );
    }

    public function test_start_period_rejects_nonexistent_member_user(): void
    {
        $tenant = $this->makeTenant('Start Ghost User');
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/board_period/start', [
                'name' => 'Ghost Member Attempt',
                'start_date' => '2026-07-01',
                'end_date' => '2027-06-30',
                'auto_close_current' => false,
                'members' => [
                    ['user_id' => (string) Str::uuid(), 'board_role_id' => BoardRole::PRESIDENT, 'unit_id' => null],
                    ['user_id' => $this->makeUser($tenant)->id, 'board_role_id' => BoardRole::TREASURER, 'unit_id' => null],
                    ['user_id' => $this->makeUser($tenant)->id, 'board_role_id' => BoardRole::SECRETARY, 'unit_id' => null],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['members.0.user_id']);

        $tenant->run(function (): void {
            $this->assertSame(0, BoardMember::count(), 'No orphan seat may be created for a nonexistent user');
        });
    }

    // =========================================================================
    // board-staff-02 — auto-close with OVERLAPPING dates + end_date truncation
    // =========================================================================

    public function test_start_period_with_overlapping_dates_auto_closes_and_truncates_previous(): void
    {
        $tenant = $this->makeTenant('Overlap Tenant');
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $token = $this->adminToken($admin);
        $oldPresident = $this->makeUser($tenant);
        $newPresident = $this->makeUser($tenant);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/board_period/start', [
                'name' => 'Periodo 2025-2026',
                'start_date' => '2025-06-01',
                'end_date' => '2026-05-31',
                'auto_close_current' => false,
                'members' => $this->trio($tenant, $oldPresident),
            ])
            ->assertStatus(201);

        // OVERLAPPING range (2026-03-01 < 2026-05-31). Pre-fix this 422'd even
        // with auto_close_current=true, making the §7.6 flow impossible.
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/board_period/start', [
                'name' => 'Periodo 2026-2027',
                'start_date' => '2026-03-01',
                'end_date' => '2027-02-28',
                'auto_close_current' => true,
                'members' => $this->trio($tenant, $newPresident),
            ])
            ->assertStatus(201);

        $tenant->run(function (): void {
            $this->assertSame(
                1,
                BoardPeriod::where('board_period_status_id', BoardPeriodStatus::CURRENT)->count(),
                'Exactly one current period after auto-close'
            );

            $old = BoardPeriod::where('name', 'Periodo 2025-2026')->firstOrFail();
            $this->assertSame(BoardPeriodStatus::CLOSED, $old->board_period_status_id, 'Previous period must be auto-closed');
            $this->assertSame(
                '2026-02-28',
                $old->end_date->format('Y-m-d'),
                'Auto-close must truncate end_date to the day before the new period starts'
            );
        });

        $this->assertFalse($this->freshUser($oldPresident->id)->hasRole('president'), 'Outgoing president loses the role');
        $this->assertTrue($this->freshUser($newPresident->id)->hasRole('president'), 'Incoming president gains the role');
    }

    public function test_early_closed_period_does_not_block_new_period(): void
    {
        $tenant = $this->makeTenant('Deadlock Tenant');
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $token = $this->adminToken($admin);

        // Start a long period and close it early (pre-fix this left a CLOSED row
        // with a future end_date that blocked any overlapping new period forever).
        $periodId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/board_period/start', [
                'name' => 'Periodo Largo',
                'start_date' => '2025-01-01',
                'end_date' => '2030-12-31',
                'auto_close_current' => false,
                'members' => $this->trio($tenant, $this->makeUser($tenant)),
            ])
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/board_period/{$periodId}/close")
            ->assertStatus(200);

        // A new period overlapping the ORIGINAL (now truncated) range must be accepted.
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/board_period/start', [
                'name' => 'Periodo Siguiente',
                'start_date' => '2027-01-01',
                'end_date' => '2028-12-31',
                'auto_close_current' => false,
                'members' => $this->trio($tenant, $this->makeUser($tenant)),
            ])
            ->assertStatus(201);
    }

    // =========================================================================
    // board-staff-16 — acta upload (PDF only, gated)
    // =========================================================================

    public function test_acta_upload_accepts_pdf_and_rejects_other_types(): void
    {
        $tenant = $this->makeTenant('Acta Tenant');
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $token = $this->adminToken($admin);
        $period = $this->makeCurrentPeriod($tenant, 'Acta 2026', '2026-01-01', '2026-12-31');

        $this->tenantRequest($token, $tenant)
            ->post("/api/board_period/{$period->id}/media", [
                'file' => $this->fakePdfFile(),
            ], ['Accept' => 'application/json'])
            ->assertStatus(201);

        $tenant->run(function () use ($period): void {
            $fresh = BoardPeriod::findOrFail($period->id);
            $this->assertSame(1, $fresh->getMedia('acta')->count(), 'Acta must be stored in the acta collection');
        });

        // Non-PDF rejected by validation.
        $this->tenantRequest($token, $tenant)
            ->post("/api/board_period/{$period->id}/media", [
                'file' => $this->fakeJpegFile(),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_acta_upload_requires_update_board_period_permission(): void
    {
        $tenant = $this->makeTenant('Acta Authz Tenant');
        $this->seedTenant($tenant);

        $resident = $this->makeUser($tenant);
        $residentToken = $resident->createToken('resident', ['read.board_period'])->plainTextToken;
        $period = $this->makeCurrentPeriod($tenant, 'Acta Authz 2026', '2026-01-01', '2026-12-31');

        $this->tenantRequest($residentToken, $tenant)
            ->post("/api/board_period/{$period->id}/media", [
                'file' => $this->fakePdfFile(),
            ], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }

    // =========================================================================
    // board-staff-15 — daily auto-close of expired periods
    // =========================================================================

    public function test_close_expired_periods_command_closes_and_revokes(): void
    {
        $tenant = $this->makeTenant('Expired Cron Tenant');
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant);

        $tenant->run(function () use ($tenant, $president): void {
            app(BoardService::class)->startPeriod(
                periodData: [
                    'name' => 'Periodo Vencido',
                    'start_date' => '2024-06-01',
                    'end_date' => '2025-05-31',
                    'auto_close_current' => false,
                ],
                membersData: $this->trio($tenant, $president),
            );
        });

        $this->assertTrue($this->freshUser($president->id)->hasRole('president'), 'Role granted while the period is current');

        $this->artisan('board:close-expired-periods')->assertSuccessful();

        $tenant->run(function (): void {
            $period = BoardPeriod::where('name', 'Periodo Vencido')->firstOrFail();
            $this->assertSame(
                BoardPeriodStatus::CLOSED,
                $period->board_period_status_id,
                'An expired CURRENT period must be auto-closed by the daily command'
            );
        });

        $this->assertFalse(
            $this->freshUser($president->id)->hasRole('president'),
            'Auto-closing an expired period must revoke the member roles'
        );
    }

    // =========================================================================
    // board-staff-14 — catalog search no longer references the dead 'key' column
    // =========================================================================

    public function test_board_catalog_search_does_not_500(): void
    {
        $tenant = $this->makeTenant('Catalog Search Tenant');
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $token = $this->adminToken($admin);

        foreach (['board_role', 'board_period_status', 'employee_position', 'pay_frequency', 'employee_shift'] as $catalog) {
            $this->tenantRequest($token, $tenant)
                ->postJson("/api/{$catalog}/search", ['search' => ['value' => 'a']])
                ->assertStatus(200);
        }
    }
}
