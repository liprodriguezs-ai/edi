<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Chat\Models\ChatChannel;
use Modules\Chat\Models\ChatChannelType;
use Modules\Housing\Models\Section;
use Modules\Housing\Models\Tower;
use Modules\Tenant\Models\Tenant;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Fase 2 — channel administration per tenant.
 *
 * An admin configures, per channel, WHO WRITES (mode) and WHO SEES/RECEIVES
 * (target_type + target_selector), creates custom channels, copies a channel's
 * config, and toggles notify_on_post. System channels stay configurable but
 * undeletable and their is_system flag is immutable. All over the REAL routes.
 */
final class ChatChannelAdminTest extends TestCase
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

    private function adminToken(User $user): string
    {
        return $user->createToken('admin', ['*'])->plainTextToken;
    }

    /**
     * Resident token WITHOUT any chat_channel write ability (only reads + post).
     */
    private function residentToken(User $user): string
    {
        return $user->createToken('resident', [
            'read.chat_channel',
            'read.chat_message',
            'create.chat_message',
        ])->plainTextToken;
    }

    private function channelId(Tenant $tenant, string $name): string
    {
        return $tenant->run(static fn (): string => ChatChannel::where('name', $name)->firstOrFail()->id);
    }

    private function makeTower(Tenant $tenant, string $name): string
    {
        return $tenant->run(static fn (): string => Tower::create(['name' => $name])->id);
    }

    private function makeSection(Tenant $tenant, string $name): string
    {
        return $tenant->run(static fn (): string => Section::create(['name' => $name])->id);
    }

    // =========================================================================
    // create with mode + target + selector + notify_on_post
    // =========================================================================

    public function test_admin_creates_custom_channel_with_write_target_target_and_notify_flag(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');
        $towerId = $this->makeTower($tenant, 'Torre A');

        // Read target = tower A residents; write target = only the board.
        $created = $this->tenantRequest($this->adminToken($admin), $tenant)->postJson('/api/chat_channel', [
            'name' => 'Canal Torre A',
            'chat_channel_type_id' => ChatChannelType::CUSTOM,
            'icon' => '🏢',
            'description' => 'Solo Torre A',
            'target_type' => 'by_tower',
            'target_selector' => ['tower_ids' => [$towerId]],
            'write_target_type' => 'board',
            'notify_on_post' => false,
        ])->assertStatus(201)->json('data');

        $tenant->run(function () use ($created, $towerId): void {
            $channel = ChatChannel::findOrFail($created['id']);
            $this->assertSame('board', $channel->write_target_type);
            $this->assertSame('by_tower', $channel->target_type);
            $this->assertSame(['tower_ids' => [$towerId]], $channel->target_selector);
            $this->assertFalse((bool) $channel->notify_on_post);
            $this->assertFalse((bool) $channel->is_system, 'Admin-created channels are never system channels');
        });
    }

    public function test_create_rejects_invalid_target_type_write_target_and_system_flag(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');
        $token = $this->adminToken($admin);

        // Unknown target_type → 422 (closed set, never silent fallback).
        $this->tenantRequest($token, $tenant)->postJson('/api/chat_channel', [
            'name' => 'Canal malo 1',
            'chat_channel_type_id' => ChatChannelType::CUSTOM,
            'target_type' => 'everyone_on_earth',
        ])->assertStatus(422)->assertJsonValidationErrors(['target_type']);

        // 'role' is a retired chat-only scope (never valid, unlike 'by_section' which
        // rejoined the shared unit-based vocabulary — see the positive test below).
        foreach (['role'] as $retiredScope) {
            $this->tenantRequest($token, $tenant)->postJson('/api/chat_channel', [
                'name' => 'Canal retirado '.$retiredScope,
                'chat_channel_type_id' => ChatChannelType::CUSTOM,
                'target_type' => $retiredScope,
            ])->assertStatus(422)->assertJsonValidationErrors(['target_type']);
        }

        // Unknown write_target_type → 422 (same closed set as the read target).
        $this->tenantRequest($token, $tenant)->postJson('/api/chat_channel', [
            'name' => 'Canal malo 2',
            'chat_channel_type_id' => ChatChannelType::CUSTOM,
            'write_target_type' => 'admins_only',
        ])->assertStatus(422)->assertJsonValidationErrors(['write_target_type']);

        // is_system cannot be set from the API → 422.
        $this->tenantRequest($token, $tenant)->postJson('/api/chat_channel', [
            'name' => 'Canal malo 3',
            'chat_channel_type_id' => ChatChannelType::CUSTOM,
            'is_system' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['is_system']);
    }

    public function test_admin_creates_channel_with_by_section_target(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');
        $sectionId = $this->makeSection($tenant, 'Seccion Norte');

        $created = $this->tenantRequest($this->adminToken($admin), $tenant)->postJson('/api/chat_channel', [
            'name' => 'Canal Seccion Norte',
            'chat_channel_type_id' => ChatChannelType::CUSTOM,
            'target_type' => 'by_section',
            'target_selector' => ['section_ids' => [$sectionId]],
        ])->assertStatus(201)->json('data');

        $tenant->run(function () use ($created, $sectionId): void {
            $channel = ChatChannel::findOrFail($created['id']);
            $this->assertSame('by_section', $channel->target_type);
            $this->assertSame(['section_ids' => [$sectionId]], $channel->target_selector);
        });
    }

    // =========================================================================
    // update mode + target + notify
    // =========================================================================

    public function test_admin_updates_write_target_target_and_notify_flag(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');
        $towerId = $this->makeTower($tenant, 'Torre B');

        $channelId = $tenant->run(static fn (): string => ChatChannel::create([
            'name' => 'Canal editable',
            'chat_channel_type_id' => ChatChannelType::CUSTOM,
            'target_type' => 'all',
            'write_target_type' => 'all',
            'notify_on_post' => true,
            'is_system' => false,
            'is_archived' => false,
        ])->id);

        $this->tenantRequest($this->adminToken($admin), $tenant)->patchJson("/api/chat_channel/{$channelId}", [
            'write_target_type' => 'board',
            'target_type' => 'by_tower',
            'target_selector' => ['tower_ids' => [$towerId]],
            'notify_on_post' => false,
        ])->assertStatus(200);

        $tenant->run(function () use ($channelId, $towerId): void {
            $channel = ChatChannel::findOrFail($channelId);
            $this->assertSame('board', $channel->write_target_type);
            $this->assertSame('by_tower', $channel->target_type);
            $this->assertSame(['tower_ids' => [$towerId]], $channel->target_selector);
            $this->assertFalse((bool) $channel->notify_on_post);
        });
    }

    // =========================================================================
    // system channels: configurable, immutable flag, undeletable
    // =========================================================================

    public function test_system_channel_config_editable_flag_immutable_and_undeletable(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');
        $token = $this->adminToken($admin);
        $oficialId = $this->channelId($tenant, 'Avisos oficiales');

        // Config (write target/target/icon) of a system channel IS editable.
        $this->tenantRequest($token, $tenant)->patchJson("/api/chat_channel/{$oficialId}", [
            'write_target_type' => 'all',
            'icon' => '🛎',
        ])->assertStatus(200);

        $tenant->run(function () use ($oficialId): void {
            $channel = ChatChannel::findOrFail($oficialId);
            $this->assertSame('all', $channel->write_target_type);
            $this->assertSame('🛎', $channel->icon);
            $this->assertTrue((bool) $channel->is_system, 'is_system stays true');
        });

        // The is_system flag is immutable from the API → 422.
        $this->tenantRequest($token, $tenant)->patchJson("/api/chat_channel/{$oficialId}", [
            'is_system' => false,
        ])->assertStatus(422)->assertJsonValidationErrors(['is_system']);

        // System channels cannot be deleted → 422.
        $this->tenantRequest($token, $tenant)->deleteJson("/api/chat_channel/{$oficialId}")->assertStatus(422);

        $tenant->run(function () use ($oficialId): void {
            $this->assertTrue((bool) ChatChannel::findOrFail($oficialId)->is_system);
        });
    }

    // =========================================================================
    // copy config
    // =========================================================================

    public function test_copy_config_copies_read_and_write_targets_from_source(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');
        $towerId = $this->makeTower($tenant, 'Torre C');

        [$sourceId, $destId] = $tenant->run(static function () use ($towerId): array {
            // Source: residents of tower C READ it, only the board WRITES.
            $source = ChatChannel::create([
                'name' => 'Canal origen',
                'chat_channel_type_id' => ChatChannelType::CUSTOM,
                'target_type' => 'by_tower',
                'target_selector' => ['tower_ids' => [$towerId]],
                'write_target_type' => 'board',
                'write_target_selector' => null,
                'is_system' => false,
                'is_archived' => false,
            ]);
            $dest = ChatChannel::create([
                'name' => 'Canal destino',
                'chat_channel_type_id' => ChatChannelType::CUSTOM,
                'target_type' => 'all',
                'target_selector' => null,
                'write_target_type' => 'all',
                'is_system' => false,
                'is_archived' => false,
            ]);

            return [$source->id, $dest->id];
        });

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson("/api/chat-channel/{$destId}/copy-config", ['source_channel_id' => $sourceId])
            ->assertStatus(200);

        $tenant->run(function () use ($destId, $towerId): void {
            $dest = ChatChannel::findOrFail($destId);
            // Read target copied.
            $this->assertSame('by_tower', $dest->target_type);
            $this->assertSame(['tower_ids' => [$towerId]], $dest->target_selector);
            // Write target copied (independently of the read target).
            $this->assertSame('board', $dest->write_target_type);
            $this->assertNull($dest->write_target_selector);
            $this->assertSame('Canal destino', $dest->name, 'copy-config must not change the name');
        });
    }

    public function test_copy_config_rejects_same_source_and_target(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');
        $generalId = $this->channelId($tenant, 'General');

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson("/api/chat-channel/{$generalId}/copy-config", ['source_channel_id' => $generalId])
            ->assertStatus(422);
    }

    // =========================================================================
    // permission gating
    // =========================================================================

    public function test_channel_admin_actions_require_permission(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeUser($tenant, 'Residente');
        $token = $this->residentToken($resident);
        $generalId = $this->channelId($tenant, 'General');
        $reportsId = $this->channelId($tenant, 'Reportes y sugerencias');

        // create
        $this->tenantRequest($token, $tenant)->postJson('/api/chat_channel', [
            'name' => 'Canal pirata',
            'chat_channel_type_id' => ChatChannelType::CUSTOM,
        ])->assertStatus(403);

        // update
        $this->tenantRequest($token, $tenant)->patchJson("/api/chat_channel/{$generalId}", [
            'write_target_type' => 'board',
        ])->assertStatus(403);

        // delete
        $this->tenantRequest($token, $tenant)->deleteJson("/api/chat_channel/{$generalId}")->assertStatus(403);

        // copy-config
        $this->tenantRequest($token, $tenant)
            ->postJson("/api/chat-channel/{$generalId}/copy-config", ['source_channel_id' => $reportsId])
            ->assertStatus(403);
    }

    // =========================================================================
    // integration: changing the target changes access immediately
    // =========================================================================

    public function test_changing_target_type_changes_access_immediately(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');
        $resident = $this->makeUser($tenant, 'Residente');
        $towerId = $this->makeTower($tenant, 'Torre Solo Admin');

        $channelId = $tenant->run(static fn (): string => ChatChannel::create([
            'name' => 'Canal mutante',
            'chat_channel_type_id' => ChatChannelType::CUSTOM,
            'target_type' => 'all',
            'is_system' => false,
            'is_archived' => false,
        ])->id);

        $residentToken = $this->residentToken($resident);

        // target=all → the resident can post.
        $this->tenantRequest($residentToken, $tenant)->postJson('/api/chat_message', [
            'chat_channel_id' => $channelId,
            'body' => 'Hola con acceso',
        ])->assertStatus(201);

        // Admin narrows the target to a tower the resident does NOT live in.
        $this->tenantRequest($this->adminToken($admin), $tenant)->patchJson("/api/chat_channel/{$channelId}", [
            'target_type' => 'by_tower',
            'target_selector' => ['tower_ids' => [$towerId]],
        ])->assertStatus(200);

        // Access is recomputed instantly from the new target: channel hidden + 403.
        $channelIds = array_map(
            static fn (array $c): string => $c['id'],
            $this->tenantRequest($residentToken, $tenant)->getJson('/api/chat_channel')->assertStatus(200)->json('data'),
        );
        $this->assertNotContains($channelId, $channelIds, 'Resident loses visibility after the target narrows');

        $this->tenantRequest($residentToken, $tenant)->postJson('/api/chat_message', [
            'chat_channel_id' => $channelId,
            'body' => 'Ya no debería poder',
        ])->assertStatus(403);
    }
}
