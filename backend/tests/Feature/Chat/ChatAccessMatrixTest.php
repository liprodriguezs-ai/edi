<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\Role as AppRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Board\Models\BoardMember;
use Modules\Board\Models\BoardPeriod;
use Modules\Board\Models\BoardPeriodStatus;
use Modules\Board\Models\BoardRole;
use Modules\Chat\Models\ChatChannel;
use Modules\Chat\Models\ChatChannelMember;
use Modules\Chat\Models\ChatChannelType;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Access matrix for Module 9 — Chat (Fase 1: "el chat funciona").
 *
 * Channel access is computed by target_type (like charges/announcements), NOT by
 * manual chat_channel_member rows. These tests pin the resulting behaviour over
 * the REAL HTTP routes:
 *
 *  - resident reads the open channels (Avisos/General/Reportes) but NOT the board
 *    channel; writes where the write target = all; is read-only where write = board.
 *  - board member (president) reads & writes every channel incl. Avisos + Mesa.
 *  - by_tower channels only reach residents with an active unit in that tower.
 *  - cross-tenant isolation: a tenant-A user never sees tenant-B channels/messages.
 *  - the channel payload exposes computed can_read / can_write flags.
 *  - membership rows are created lazily on read (per-user state), never a gate.
 */
final class ChatAccessMatrixTest extends TestCase
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

    private function makeUser(Tenant $tenant, string $name = 'Chat User'): User
    {
        $user = User::create([
            'name' => $name.' '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        return $user;
    }

    /**
     * @param  array<string>|null  $abilities
     */
    private function residentToken(User $user, ?array $abilities = null): string
    {
        return $user->createToken('resident', $abilities ?? [
            'read.chat_channel',
            'read.chat_message',
            'read.chat_channel_member',
            'create.chat_message',
        ])->plainTextToken;
    }

    private function seedGlobalBoardRoles(): void
    {
        foreach (['president', 'treasurer', 'secretary', 'vocal', 'oversight_committee'] as $slug) {
            AppRole::firstOrCreate(['name' => $slug, 'guard_name' => 'api']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Register a user as board member of THIS tenant's current board period —
     * the real alta that grants tenant-scoped board access (board access is no
     * longer derived from the global Spatie role alone).
     */
    private function makeCurrentBoardMember(Tenant $tenant, User $user): void
    {
        $tenant->run(static function () use ($user): void {
            $period = BoardPeriod::create([
                'name' => 'Mesa 2026',
                'start_date' => now()->subMonth()->toDateString(),
                'end_date' => now()->addYear()->toDateString(),
                'board_period_status_id' => BoardPeriodStatus::CURRENT,
            ]);

            BoardMember::create([
                'board_period_id' => $period->id,
                'user_id' => $user->id,
                'board_role_id' => BoardRole::PRESIDENT,
            ]);
        });
    }

    private function channelId(Tenant $tenant, string $name): string
    {
        return $tenant->run(static fn (): string => ChatChannel::where('name', $name)->firstOrFail()->id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listChannels(string $token, Tenant $tenant): array
    {
        return $this->tenantRequest($token, $tenant)
            ->getJson('/api/chat_channel')
            ->assertStatus(200)
            ->json('data');
    }

    /**
     * @param  array<int, array<string, mixed>>  $channels
     */
    private function channelByName(array $channels, string $name): ?array
    {
        foreach ($channels as $channel) {
            if (($channel['name'] ?? null) === $name) {
                return $channel;
            }
        }

        return null;
    }

    // =========================================================================
    // A. Resident: open channels visible, board channel hidden
    // =========================================================================

    public function test_resident_sees_open_channels_but_not_board_channel(): void
    {
        $this->seedGlobalBoardRoles();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeUser($tenant);

        $channels = $this->listChannels($this->residentToken($resident), $tenant);
        $names = array_map(static fn (array $c): string => $c['name'], $channels);

        $this->assertContains('Avisos oficiales', $names);
        $this->assertContains('General', $names);
        $this->assertContains('Reportes y sugerencias', $names);
        $this->assertNotContains('Mesa directiva', $names, 'Board channel must be hidden from plain residents');
    }

    // =========================================================================
    // B. Resident write matrix: write target = all yes, write target = board no
    // =========================================================================

    public function test_resident_can_write_open_channels_but_not_official(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeUser($tenant);
        $token = $this->residentToken($resident);

        $generalId = $this->channelId($tenant, 'General');
        $reportsId = $this->channelId($tenant, 'Reportes y sugerencias');
        $oficialId = $this->channelId($tenant, 'Avisos oficiales');

        // No prior join — access is by target_type, posting must work directly.
        $this->tenantRequest($token, $tenant)->postJson('/api/chat_message', [
            'chat_channel_id' => $generalId,
            'body' => 'Hola vecinos',
        ])->assertStatus(201);

        $this->tenantRequest($token, $tenant)->postJson('/api/chat_message', [
            'chat_channel_id' => $reportsId,
            'body' => 'Se fundió una lámpara en el pasillo',
        ])->assertStatus(201);

        // Official channel write target = board → resident is blocked from posting.
        $this->tenantRequest($token, $tenant)->postJson('/api/chat_message', [
            'chat_channel_id' => $oficialId,
            'body' => 'Aviso pirata',
        ])->assertStatus(403);
    }

    // =========================================================================
    // C. Board member reads & writes everything (Avisos + Mesa directiva)
    // =========================================================================

    public function test_board_member_reads_and_writes_official_and_board_channels(): void
    {
        $this->seedGlobalBoardRoles();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'Presidenta');
        $president->assignRole('president');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // Board access is tenant-scoped: the real alta is a board_member row of
        // the current period, not just the (global) Spatie role.
        $this->makeCurrentBoardMember($tenant, $president);

        $token = $this->residentToken($president);

        // Board channel is visible to the board.
        $channels = $this->listChannels($token, $tenant);
        $names = array_map(static fn (array $c): string => $c['name'], $channels);
        $this->assertContains('Mesa directiva', $names, 'Board member must see the board channel');

        $oficialId = $this->channelId($tenant, 'Avisos oficiales');
        $mesaId = $this->channelId($tenant, 'Mesa directiva');

        // Writes the official channel (write target = board) — board is allowed.
        $this->tenantRequest($token, $tenant)->postJson('/api/chat_message', [
            'chat_channel_id' => $oficialId,
            'body' => 'Aviso oficial de la mesa',
        ])->assertStatus(201);

        // Writes & reads the board channel.
        $boardMsg = $this->tenantRequest($token, $tenant)->postJson('/api/chat_message', [
            'chat_channel_id' => $mesaId,
            'body' => 'Tema interno de la mesa',
        ])->assertStatus(201)->json('data');

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/chat_message/{$boardMsg['id']}")
            ->assertStatus(200)
            ->assertJsonPath('data.body', 'Tema interno de la mesa');
    }

    // =========================================================================
    // C2. Bleed guard — a GLOBAL board role without a board_member row in THIS
    // tenant grants NO board access (closes the cross-tenant role leak).
    // =========================================================================

    public function test_global_board_role_without_tenant_membership_has_no_board_access(): void
    {
        $this->seedGlobalBoardRoles();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        // User holds the GLOBAL 'president' Spatie role (e.g. board member of a
        // DIFFERENT tenant) but is NOT a board_member of this tenant's period.
        $foreignPresident = $this->makeUser($tenant, 'Presidente de otro cluster');
        $foreignPresident->assignRole('president');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $token = $this->residentToken($foreignPresident);

        // Does NOT see the board channel.
        $names = array_map(
            static fn (array $c): string => $c['name'],
            $this->listChannels($token, $tenant),
        );
        $this->assertNotContains('Mesa directiva', $names, 'Global role alone must not reveal the board channel');

        // Cannot write the official read-only channel (no tenant board membership).
        $oficialId = $this->channelId($tenant, 'Avisos oficiales');
        $this->tenantRequest($token, $tenant)->postJson('/api/chat_message', [
            'chat_channel_id' => $oficialId,
            'body' => 'No soy de la mesa de este cluster',
        ])->assertStatus(403);

        // Cannot even access (post to) the board channel.
        $mesaId = $this->channelId($tenant, 'Mesa directiva');
        $this->tenantRequest($token, $tenant)->postJson('/api/chat_message', [
            'chat_channel_id' => $mesaId,
            'body' => 'Tampoco entro acá',
        ])->assertStatus(403);
    }

    // =========================================================================
    // D. by_tower channel only reaches residents of that tower
    // =========================================================================

    public function test_by_tower_channel_only_visible_to_tower_residents(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $residentA = $this->makeUser($tenant, 'Residente Torre A');
        $residentB = $this->makeUser($tenant, 'Residente Torre B');

        $towerChannelId = $tenant->run(static function () use ($residentA, $residentB): string {
            $towerA = Tower::create(['name' => 'Torre A']);
            $towerB = Tower::create(['name' => 'Torre B']);

            $makeUnit = static fn (Tower $tower, string $number): Unit => Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Departamento'),
                'number' => $number,
                'tower_id' => $tower->id,
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);

            UnitUser::create([
                'unit_id' => $makeUnit($towerA, 'A-101')->id,
                'user_id' => $residentA->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ]);
            UnitUser::create([
                'unit_id' => $makeUnit($towerB, 'B-201')->id,
                'user_id' => $residentB->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ]);

            return ChatChannel::create([
                'name' => 'Canal Torre A',
                'chat_channel_type_id' => ChatChannelType::CUSTOM,
                'target_type' => 'by_tower',
                'target_selector' => ['tower_ids' => [$towerA->id]],
                'is_moderated' => false,
                'is_system' => false,
                'is_archived' => false,
            ])->id;
        });

        // Resident of tower A sees and writes the channel.
        $namesA = array_map(
            static fn (array $c): string => $c['name'],
            $this->listChannels($this->residentToken($residentA), $tenant),
        );
        $this->assertContains('Canal Torre A', $namesA);
        $this->tenantRequest($this->residentToken($residentA), $tenant)->postJson('/api/chat_message', [
            'chat_channel_id' => $towerChannelId,
            'body' => 'Hola Torre A',
        ])->assertStatus(201);

        // Resident of tower B never sees nor posts to it.
        $namesB = array_map(
            static fn (array $c): string => $c['name'],
            $this->listChannels($this->residentToken($residentB), $tenant),
        );
        $this->assertNotContains('Canal Torre A', $namesB, 'Tower B resident must not see Tower A channel');
        $this->tenantRequest($this->residentToken($residentB), $tenant)->postJson('/api/chat_message', [
            'chat_channel_id' => $towerChannelId,
            'body' => 'No pertenezco a esta torre',
        ])->assertStatus(403);
    }

    // =========================================================================
    // D bis. Independent WRITE target: everyone READS, only one tower WRITES
    // =========================================================================

    public function test_write_target_is_independent_of_read_target(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $residentA = $this->makeUser($tenant, 'Residente Torre A');
        $residentB = $this->makeUser($tenant, 'Residente Torre B');

        $channelId = $tenant->run(static function () use ($residentA, $residentB): string {
            $towerA = Tower::create(['name' => 'Torre A']);
            $towerB = Tower::create(['name' => 'Torre B']);

            $makeUnit = static fn (Tower $tower, string $number): Unit => Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Departamento'),
                'number' => $number,
                'tower_id' => $tower->id,
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);

            UnitUser::create([
                'unit_id' => $makeUnit($towerA, 'A-101')->id,
                'user_id' => $residentA->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ]);
            UnitUser::create([
                'unit_id' => $makeUnit($towerB, 'B-201')->id,
                'user_id' => $residentB->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ]);

            // READ = everyone in the cluster; WRITE = only Tower A residents.
            return ChatChannel::create([
                'name' => 'Anuncios con respuesta Torre A',
                'chat_channel_type_id' => ChatChannelType::CUSTOM,
                'target_type' => 'all',
                'target_selector' => null,
                'write_target_type' => 'by_tower',
                'write_target_selector' => ['tower_ids' => [$towerA->id]],
                'is_moderated' => false,
                'is_system' => false,
                'is_archived' => false,
            ])->id;
        });

        // Both residents SEE the channel (read target = all).
        foreach ([$residentA, $residentB] as $resident) {
            $names = array_map(
                static fn (array $c): string => $c['name'],
                $this->listChannels($this->residentToken($resident), $tenant),
            );
            $this->assertContains('Anuncios con respuesta Torre A', $names);
        }

        // Tower A resident may WRITE (matches the write target).
        $this->tenantRequest($this->residentToken($residentA), $tenant)->postJson('/api/chat_message', [
            'chat_channel_id' => $channelId,
            'body' => 'Soy de la Torre A, puedo escribir',
        ])->assertStatus(201);

        // Tower B resident READS but is blocked from WRITING (write target = Tower A only).
        $this->tenantRequest($this->residentToken($residentB), $tenant)->postJson('/api/chat_message', [
            'chat_channel_id' => $channelId,
            'body' => 'Veo el canal pero no debería poder escribir',
        ])->assertStatus(403);
    }

    // =========================================================================
    // E. Cross-tenant isolation
    // =========================================================================

    public function test_cross_tenant_user_cannot_see_other_tenant_channels_or_messages(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $userA = $this->makeUser($tenantA, 'Vecino A');
        $userB = $this->makeUser($tenantB, 'Vecino B');

        $generalA = $this->channelId($tenantA, 'General');

        // userA posts in tenant A.
        $this->tenantRequest($this->residentToken($userA), $tenantA)->postJson('/api/chat_message', [
            'chat_channel_id' => $generalA,
            'body' => 'Mensaje exclusivo de A',
        ])->assertStatus(201);

        // userB only sees tenant B channels.
        $channelIdsB = array_map(
            static fn (array $c): string => $c['id'],
            $this->listChannels($this->residentToken($userB), $tenantB),
        );
        $this->assertNotContains($generalA, $channelIdsB, 'Tenant B must not see Tenant A channels');

        // userB sees no messages at all (none in B), never A's message.
        $messagesB = $this->tenantRequest($this->residentToken($userB), $tenantB)
            ->getJson('/api/chat_message')
            ->assertStatus(200)
            ->json('data');
        $this->assertSame([], $messagesB, 'Tenant B must not read Tenant A messages');
    }

    // =========================================================================
    // F. can_read / can_write flags in the channel payload
    // =========================================================================

    public function test_channel_payload_exposes_can_read_and_can_write_flags(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeUser($tenant);

        $channels = $this->listChannels($this->residentToken($resident), $tenant);

        $general = $this->channelByName($channels, 'General');
        $oficial = $this->channelByName($channels, 'Avisos oficiales');

        $this->assertNotNull($general);
        $this->assertNotNull($oficial);

        $this->assertTrue((bool) $general['can_read'], 'Resident can read General');
        $this->assertTrue((bool) $general['can_write'], 'Resident can write General (write target = all)');

        $this->assertTrue((bool) $oficial['can_read'], 'Resident can read Avisos');
        $this->assertFalse((bool) $oficial['can_write'], 'Resident cannot write Avisos (read-only)');
    }

    // =========================================================================
    // G. Membership is created lazily on read — not a gate for access
    // =========================================================================

    public function test_reading_a_channel_lazily_creates_membership_for_per_user_state(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeUser($tenant);
        $generalId = $this->channelId($tenant, 'General');

        // Pre-condition: no membership row yet.
        $tenant->run(function () use ($generalId, $resident): void {
            $this->assertFalse(
                ChatChannelMember::where('chat_channel_id', $generalId)->where('user_id', $resident->id)->exists(),
                'No membership should exist before the resident opens the channel'
            );
        });

        // Opening (show) the channel creates the per-user state row.
        $this->tenantRequest($this->residentToken($resident), $tenant)
            ->getJson("/api/chat_channel/{$generalId}")
            ->assertStatus(200);

        $tenant->run(function () use ($generalId, $resident): void {
            $this->assertTrue(
                ChatChannelMember::where('chat_channel_id', $generalId)->where('user_id', $resident->id)->exists(),
                'Opening a channel must lazily create the membership row for per-user state'
            );
        });
    }
}
