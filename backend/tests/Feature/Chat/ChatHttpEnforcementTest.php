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
use Modules\Chat\Http\Controllers\ChatMessageController;
use Modules\Chat\Models\ChatChannel;
use Modules\Chat\Models\ChatChannelMember;
use Modules\Chat\Models\ChatChannelType;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatMessageReport;
use Modules\Chat\Services\ChatService;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Notification\Models\NotificationUser;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * HTTP enforcement tests for Module 9 — Chat (findings chat-01..chat-12, authz-04).
 *
 * Every check here goes through the REAL routes with Sanctum token abilities:
 *  - store via Orion delegates to ChatService (membership/read-only/rate limit)
 *  - author spoofing impossible (user_id/hidden ignored)
 *  - reads gated by channel membership; hidden bodies redacted
 *  - join enforces target_type/target_selector rules
 *  - report flow: validation, duplicates 422, hide resolves, dismiss
 *  - author-only edit window; Orion update/destroy not routed
 *  - idempotent private 1:1 channels
 */
final class ChatHttpEnforcementTest extends TestCase
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
     * @param  array<string>  $abilities
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
     * board chat access is tenant-scoped (board_member of the current period),
     * not derived from the global Spatie role alone.
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

    private function joinViaService(Tenant $tenant, string $channelId, string $userId): void
    {
        $tenant->run(static function () use ($channelId, $userId): void {
            app(ChatService::class)->addMember(ChatChannel::findOrFail($channelId), $userId);
        });
    }

    // =========================================================================
    // chat-01 — Orion store enforces channel ACCESS (by target_type) + read-only
    // mode. Access is computed from the channel target, not a manual membership.
    // =========================================================================

    public function test_store_enforces_channel_access_by_target_not_membership(): void
    {
        $this->seedGlobalBoardRoles();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeUser($tenant);
        $token = $this->residentToken($resident);
        $generalId = $this->channelId($tenant, 'General');
        $mesaId = $this->channelId($tenant, 'Mesa directiva');

        // Open channel: posting works WITHOUT any prior join — access is by target.
        $this->tenantRequest($token, $tenant)->postJson('/api/chat_message', [
            'chat_channel_id' => $generalId,
            'body' => 'Hola vecinos, sin necesidad de unirme',
        ])->assertStatus(201);

        // Posting lazily created the author's per-user state row.
        $tenant->run(function () use ($generalId, $resident): void {
            $this->assertTrue(
                ChatChannelMember::where('chat_channel_id', $generalId)->where('user_id', $resident->id)->exists(),
                'Posting must lazily create the author membership row'
            );
            $this->assertSame(1, ChatMessage::count());
        });

        // Board channel: a plain resident does not match the target → 403, no message.
        $this->tenantRequest($token, $tenant)->postJson('/api/chat_message', [
            'chat_channel_id' => $mesaId,
            'body' => 'No pertenezco a la mesa',
        ])->assertStatus(403);

        $tenant->run(function (): void {
            $this->assertSame(1, ChatMessage::count(), 'A non-board resident cannot post to the board channel');
        });
    }

    public function test_resident_cannot_post_in_read_only_channel_via_http(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeUser($tenant);
        $token = $this->residentToken($resident);
        $oficialId = $this->channelId($tenant, 'Avisos oficiales');

        $this->tenantRequest($token, $tenant)->postJson("/api/chat-channel/{$oficialId}/join")->assertStatus(200);

        $this->tenantRequest($token, $tenant)->postJson('/api/chat_message', [
            'chat_channel_id' => $oficialId,
            'body' => 'Aviso pirata',
        ])->assertStatus(403);
    }

    // =========================================================================
    // chat-02 — author spoofing impossible: user_id/hidden of payload ignored
    // =========================================================================

    public function test_post_ignores_spoofed_user_id_and_hidden_flag(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeUser($tenant);
        $victim = $this->makeUser($tenant, 'Presidente Víctima');
        $token = $this->residentToken($resident);
        $generalId = $this->channelId($tenant, 'General');

        $this->tenantRequest($token, $tenant)->postJson("/api/chat-channel/{$generalId}/join")->assertStatus(200);

        $created = $this->tenantRequest($token, $tenant)->postJson('/api/chat_message', [
            'chat_channel_id' => $generalId,
            'user_id' => $victim->id,
            'hidden' => true,
            'edited_at' => now()->toIso8601String(),
            'body' => 'Mensaje con autor falsificado',
        ])->assertStatus(201)->json('data');

        $tenant->run(function () use ($created, $resident): void {
            $message = ChatMessage::findOrFail($created['id']);
            $this->assertSame($resident->id, $message->user_id, 'Author is ALWAYS the authenticated user');
            $this->assertFalse((bool) $message->hidden, 'hidden must never come from the payload');
            $this->assertNull($message->edited_at, 'edited_at must never come from the payload');
        });
    }

    // =========================================================================
    // chat-03 / authz-04 — reads gated by membership; hidden bodies redacted
    // =========================================================================

    public function test_resident_cannot_read_board_channel_messages_or_members(): void
    {
        $this->seedGlobalBoardRoles();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeUser($tenant);
        $president = $this->makeUser($tenant, 'Presidenta');
        $president->assignRole('president');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->makeCurrentBoardMember($tenant, $president);

        $mesaId = $this->channelId($tenant, 'Mesa directiva');

        $messageId = $tenant->run(static function () use ($mesaId, $president): string {
            $service = app(ChatService::class);
            $channel = ChatChannel::findOrFail($mesaId);
            $service->joinChannel($channel, $president->id);

            return $service->postMessage($channel, $president->id, 'Tema confidencial de la mesa')->id;
        });

        $residentToken = $this->residentToken($resident);

        // Index: no board messages leak.
        $index = $this->tenantRequest($residentToken, $tenant)->getJson('/api/chat_message')->assertStatus(200)->json('data');
        $this->assertSame([], $index, 'A non-member resident must not read any channel messages');

        // Search filtered by the board channel: empty.
        $search = $this->tenantRequest($residentToken, $tenant)->postJson('/api/chat_message/search', [
            'filters' => [['field' => 'chat_channel_id', 'operator' => '=', 'value' => $mesaId]],
        ])->assertStatus(200)->json('data');
        $this->assertSame([], $search, 'Filtering by the board channel must not leak its messages');

        // Show: 404 for non-members.
        $this->tenantRequest($residentToken, $tenant)->getJson("/api/chat_message/{$messageId}")->assertStatus(404);

        // Member list of the board channel: empty for the resident.
        $members = $this->tenantRequest($residentToken, $tenant)->postJson('/api/chat_channel_member/search', [
            'filters' => [['field' => 'chat_channel_id', 'operator' => '=', 'value' => $mesaId]],
        ])->assertStatus(200)->json('data');
        $this->assertSame([], $members, 'Board channel member list must not leak');

        // A moderator (wildcard token) still reads everything.
        $admin = $this->makeUser($tenant, 'Admin');
        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->getJson("/api/chat_message/{$messageId}")
            ->assertStatus(200)
            ->assertJsonPath('data.body', 'Tema confidencial de la mesa');
    }

    public function test_hidden_message_body_is_redacted_for_non_moderators(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $author = $this->makeUser($tenant, 'Autora');
        $reader = $this->makeUser($tenant, 'Lector');
        $admin = $this->makeUser($tenant, 'Admin');
        $generalId = $this->channelId($tenant, 'General');

        $this->joinViaService($tenant, $generalId, $author->id);
        $this->joinViaService($tenant, $generalId, $reader->id);

        $messageId = $tenant->run(static fn (): string => app(ChatService::class)
            ->postMessage(ChatChannel::findOrFail($generalId), $author->id, 'Contenido ofensivo original')->id);

        $adminToken = $this->adminToken($admin);
        $this->tenantRequest($adminToken, $tenant)->postJson("/api/chat-message/{$messageId}/hide")->assertStatus(200);

        // Non-moderator member sees the placeholder, never the original body.
        $this->tenantRequest($this->residentToken($reader), $tenant)
            ->getJson("/api/chat_message/{$messageId}")
            ->assertStatus(200)
            ->assertJsonPath('data.body', ChatMessageController::HIDDEN_BODY_PLACEHOLDER);

        // Moderator keeps audit access to the original content.
        $this->tenantRequest($adminToken, $tenant)
            ->getJson("/api/chat_message/{$messageId}")
            ->assertStatus(200)
            ->assertJsonPath('data.body', 'Contenido ofensivo original');
    }

    // =========================================================================
    // chat-04 — join enforces target_type/target_selector rules
    // =========================================================================

    public function test_join_enforces_board_and_tower_rules(): void
    {
        $this->seedGlobalBoardRoles();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeUser($tenant);
        $president = $this->makeUser($tenant, 'Presidenta');
        $president->assignRole('president');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->makeCurrentBoardMember($tenant, $president);

        $mesaId = $this->channelId($tenant, 'Mesa directiva');

        // Board channel: plain resident rejected, board member admitted.
        $this->tenantRequest($this->residentToken($resident), $tenant)
            ->postJson("/api/chat-channel/{$mesaId}/join")->assertStatus(403);
        $this->tenantRequest($this->residentToken($president), $tenant)
            ->postJson("/api/chat-channel/{$mesaId}/join")->assertStatus(200);

        // Tower-scoped channel: only residents with an active unit in the tower.
        $residentTowerA = $this->makeUser($tenant, 'Residente Torre A');
        $residentTowerB = $this->makeUser($tenant, 'Residente Torre B');

        $towerChannelId = $tenant->run(static function () use ($residentTowerA, $residentTowerB): string {
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
                'user_id' => $residentTowerA->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ]);
            UnitUser::create([
                'unit_id' => $makeUnit($towerB, 'B-201')->id,
                'user_id' => $residentTowerB->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ]);

            return ChatChannel::create([
                'name' => 'Torre A',
                'chat_channel_type_id' => ChatChannelType::CUSTOM,
                'target_type' => 'by_tower',
                'target_selector' => ['tower_ids' => [$towerA->id]],
                'is_moderated' => false,
                'is_system' => false,
                'is_archived' => false,
            ])->id;
        });

        $this->tenantRequest($this->residentToken($residentTowerB), $tenant)
            ->postJson("/api/chat-channel/{$towerChannelId}/join")->assertStatus(403);
        $this->tenantRequest($this->residentToken($residentTowerA), $tenant)
            ->postJson("/api/chat-channel/{$towerChannelId}/join")->assertStatus(200);

        $tenant->run(function () use ($towerChannelId, $residentTowerA, $residentTowerB): void {
            $this->assertTrue(ChatChannelMember::where('chat_channel_id', $towerChannelId)
                ->where('user_id', $residentTowerA->id)->exists());
            $this->assertFalse(ChatChannelMember::where('chat_channel_id', $towerChannelId)
                ->where('user_id', $residentTowerB->id)->exists());
        });
    }

    // =========================================================================
    // chat-05 / chat-10 — report flow: validation, duplicates, hide, dismiss
    // =========================================================================

    public function test_report_requires_reason_and_rejects_duplicates(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $author = $this->makeUser($tenant, 'Autor');
        $reporter = $this->makeUser($tenant, 'Reportera');
        $outsider = $this->makeUser($tenant, 'Externo');
        $generalId = $this->channelId($tenant, 'General');

        $this->joinViaService($tenant, $generalId, $author->id);
        $this->joinViaService($tenant, $generalId, $reporter->id);

        $messageId = $tenant->run(static fn (): string => app(ChatService::class)
            ->postMessage(ChatChannel::findOrFail($generalId), $author->id, 'Mensaje reportable')->id);

        $reporterToken = $this->residentToken($reporter);

        // reason is required (and capped at 255).
        $this->tenantRequest($reporterToken, $tenant)
            ->postJson("/api/chat-message/{$messageId}/report", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->tenantRequest($reporterToken, $tenant)
            ->postJson("/api/chat-message/{$messageId}/report", ['reason' => Str::random(300)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->tenantRequest($reporterToken, $tenant)
            ->postJson("/api/chat-message/{$messageId}/report", ['reason' => 'Ofensivo'])
            ->assertStatus(201);

        // Duplicate (same user + message) → 422, no second row.
        $this->tenantRequest($reporterToken, $tenant)
            ->postJson("/api/chat-message/{$messageId}/report", ['reason' => 'Ofensivo otra vez'])
            ->assertStatus(422);

        // A user WITHOUT access to a restricted channel cannot report its messages.
        $this->seedGlobalBoardRoles();
        $president = $this->makeUser($tenant, 'Presidenta');
        $president->assignRole('president');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->makeCurrentBoardMember($tenant, $president);

        $mesaId = $this->channelId($tenant, 'Mesa directiva');
        $boardMessageId = $tenant->run(static fn (): string => app(ChatService::class)
            ->postMessage(ChatChannel::findOrFail($mesaId), $president->id, 'Tema de la mesa')->id);

        $this->tenantRequest($this->residentToken($outsider), $tenant)
            ->postJson("/api/chat-message/{$boardMessageId}/report", ['reason' => 'Spam'])
            ->assertStatus(403);

        $tenant->run(function () use ($messageId, $boardMessageId): void {
            $this->assertSame(1, ChatMessageReport::where('chat_message_id', $messageId)->count());
            $this->assertSame(0, ChatMessageReport::where('chat_message_id', $boardMessageId)->count());
        });
    }

    public function test_hide_resolves_pending_reports_and_dismiss_closes_them(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $author = $this->makeUser($tenant, 'Autor');
        $reporter = $this->makeUser($tenant, 'Reportera');
        $admin = $this->makeUser($tenant, 'Presidente Revisor');
        $generalId = $this->channelId($tenant, 'General');

        $this->joinViaService($tenant, $generalId, $author->id);
        $this->joinViaService($tenant, $generalId, $reporter->id);

        [$hiddenPathMessageId, $dismissPathMessageId] = $tenant->run(static function () use ($generalId, $author): array {
            $service = app(ChatService::class);
            $channel = ChatChannel::findOrFail($generalId);

            return [
                $service->postMessage($channel, $author->id, 'Mensaje que será ocultado')->id,
                $service->postMessage($channel, $author->id, 'Mensaje que será desestimado')->id,
            ];
        });

        $reporterToken = $this->residentToken($reporter);
        $reportA = $this->tenantRequest($reporterToken, $tenant)
            ->postJson("/api/chat-message/{$hiddenPathMessageId}/report", ['reason' => 'Ofensivo'])
            ->assertStatus(201)->json('data');
        $reportB = $this->tenantRequest($reporterToken, $tenant)
            ->postJson("/api/chat-message/{$dismissPathMessageId}/report", ['reason' => 'No me gusta'])
            ->assertStatus(201)->json('data');

        $adminToken = $this->adminToken($admin);

        // The board can list pending reports; residents cannot.
        $this->tenantRequest($adminToken, $tenant)->getJson('/api/chat_message_report')->assertStatus(200);
        $this->tenantRequest($reporterToken, $tenant)->getJson('/api/chat_message_report')->assertStatus(403);

        // Hide → linked pending report resolved as reviewed.
        $this->tenantRequest($adminToken, $tenant)
            ->postJson("/api/chat-message/{$hiddenPathMessageId}/hide")->assertStatus(200);

        // Dismiss → report dismissed, message stays visible.
        $this->tenantRequest($adminToken, $tenant)
            ->postJson("/api/chat-message-report/{$reportB['id']}/dismiss")->assertStatus(200);

        // Dismissing an already-resolved report fails cleanly.
        $this->tenantRequest($adminToken, $tenant)
            ->postJson("/api/chat-message-report/{$reportB['id']}/dismiss")->assertStatus(422);

        // Residents cannot dismiss.
        $this->tenantRequest($reporterToken, $tenant)
            ->postJson("/api/chat-message-report/{$reportA['id']}/dismiss")->assertStatus(403);

        $tenant->run(function () use ($reportA, $reportB, $hiddenPathMessageId, $dismissPathMessageId): void {
            $this->assertSame(ChatMessageReport::STATUS_REVIEWED, ChatMessageReport::findOrFail($reportA['id'])->status);
            $this->assertSame(ChatMessageReport::STATUS_DISMISSED, ChatMessageReport::findOrFail($reportB['id'])->status);
            $this->assertTrue((bool) ChatMessage::findOrFail($hiddenPathMessageId)->hidden);
            $this->assertFalse((bool) ChatMessage::findOrFail($dismissPathMessageId)->hidden);
        });
    }

    // =========================================================================
    // chat-07 / chat-08 — author-only edit window; moderation never rewrites
    // =========================================================================

    public function test_author_edits_own_message_within_window_others_cannot(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $author = $this->makeUser($tenant, 'Autora');
        $other = $this->makeUser($tenant, 'Vecino');
        $generalId = $this->channelId($tenant, 'General');

        $this->joinViaService($tenant, $generalId, $author->id);
        $this->joinViaService($tenant, $generalId, $other->id);

        $messageId = $tenant->run(static fn (): string => app(ChatService::class)
            ->postMessage(ChatChannel::findOrFail($generalId), $author->id, 'Texto original')->id);

        // Another member cannot edit someone else's message — even with the
        // moderation ability, the edit endpoint is ownership-gated.
        $this->tenantRequest($this->residentToken($other, ['read.chat_message', 'update.chat_message']), $tenant)
            ->postJson("/api/chat-message/{$messageId}/edit", ['body' => 'Texto reescrito por un tercero'])
            ->assertStatus(403);

        // The author edits within the window; edited_at is stamped server-side.
        $this->tenantRequest($this->residentToken($author), $tenant)
            ->postJson("/api/chat-message/{$messageId}/edit", ['body' => 'Texto corregido'])
            ->assertStatus(200);

        $tenant->run(function () use ($messageId): void {
            $message = ChatMessage::findOrFail($messageId);
            $this->assertSame('Texto corregido', $message->body);
            $this->assertNotNull($message->edited_at, 'edited_at must be stamped server-side');
        });

        // Window expired → 422 even for the author.
        $tenant->run(static function () use ($messageId): void {
            ChatMessage::where('id', $messageId)->update([
                'created_at' => now()->subMinutes(ChatService::EDIT_WINDOW_MINUTES + 5),
            ]);
        });

        $this->tenantRequest($this->residentToken($author), $tenant)
            ->postJson("/api/chat-message/{$messageId}/edit", ['body' => 'Edición tardía'])
            ->assertStatus(422);
    }

    public function test_orion_update_and_destroy_routes_are_not_registered(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $author = $this->makeUser($tenant, 'Autora');
        $admin = $this->makeUser($tenant, 'Vocal');
        $generalId = $this->channelId($tenant, 'General');

        $this->joinViaService($tenant, $generalId, $author->id);

        $messageId = $tenant->run(static fn (): string => app(ChatService::class)
            ->postMessage(ChatChannel::findOrFail($generalId), $author->id, 'Mensaje probatorio')->id);

        // Even a wildcard token cannot rewrite or delete message rows via REST:
        // the routes simply do not exist (§13.4.4 — hide, never alter/delete).
        $adminToken = $this->adminToken($admin);
        $this->tenantRequest($adminToken, $tenant)
            ->patchJson("/api/chat_message/{$messageId}", ['body' => 'Reescrito por moderación'])
            ->assertStatus(405);
        $this->tenantRequest($adminToken, $tenant)
            ->deleteJson("/api/chat_message/{$messageId}")
            ->assertStatus(405);

        $tenant->run(function () use ($messageId): void {
            $message = ChatMessage::findOrFail($messageId);
            $this->assertSame('Mensaje probatorio', $message->body);
            $this->assertNull($message->deleted_at);
        });
    }

    // =========================================================================
    // chat-06 — direct 1:1 channels: idempotent, tenant-bound, private
    // =========================================================================

    public function test_direct_channel_is_idempotent_private_and_tenant_bound(): void
    {
        $tenantA = $this->makeTenant('Direct A');
        $tenantB = $this->makeTenant('Direct B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $alice = $this->makeUser($tenantA, 'Alice');
        $bruno = $this->makeUser($tenantA, 'Bruno');
        $carla = $this->makeUser($tenantA, 'Carla');
        $foreigner = $this->makeUser($tenantB, 'Forastero');

        $aliceToken = $this->residentToken($alice);
        $brunoToken = $this->residentToken($bruno);

        // Open the 1:1 — both members created.
        $first = $this->tenantRequest($aliceToken, $tenantA)
            ->postJson('/api/chat_channel/direct', ['user_id' => $bruno->id])
            ->assertStatus(200)->json('data');

        $this->assertCount(2, $first['members'] ?? [], 'Direct channel must have exactly both members');

        // Idempotent from either side.
        $again = $this->tenantRequest($aliceToken, $tenantA)
            ->postJson('/api/chat_channel/direct', ['user_id' => $bruno->id])
            ->assertStatus(200)->json('data');
        $mirror = $this->tenantRequest($brunoToken, $tenantA)
            ->postJson('/api/chat_channel/direct', ['user_id' => $alice->id])
            ->assertStatus(200)->json('data');
        $this->assertSame($first['id'], $again['id']);
        $this->assertSame($first['id'], $mirror['id']);

        $tenantA->run(function () use ($first): void {
            $this->assertSame(2, ChatChannelMember::where('chat_channel_id', $first['id'])->count());
        });

        // Self-chat and cross-tenant recipients are rejected.
        $this->tenantRequest($aliceToken, $tenantA)
            ->postJson('/api/chat_channel/direct', ['user_id' => $alice->id])
            ->assertStatus(422);
        $this->tenantRequest($aliceToken, $tenantA)
            ->postJson('/api/chat_channel/direct', ['user_id' => $foreigner->id])
            ->assertStatus(422);

        // A third user can neither join nor list the direct channel.
        $carlaToken = $this->residentToken($carla);
        $this->tenantRequest($carlaToken, $tenantA)
            ->postJson("/api/chat-channel/{$first['id']}/join")
            ->assertStatus(403);

        $carlaChannels = collect(
            $this->tenantRequest($carlaToken, $tenantA)->getJson('/api/chat_channel')->assertStatus(200)->json('data')
        )->pluck('id');
        $this->assertNotContains($first['id'], $carlaChannels, 'Direct channels must be hidden from outsiders');

        $aliceChannels = collect(
            $this->tenantRequest($aliceToken, $tenantA)->getJson('/api/chat_channel')->assertStatus(200)->json('data')
        )->pluck('id');
        $this->assertContains($first['id'], $aliceChannels, 'Members must see their own direct channel');

        // §13.6 'Mensaje 1:1 → Push → Receptor': posting notifies the other member.
        $this->tenantRequest($aliceToken, $tenantA)->postJson('/api/chat_message', [
            'chat_channel_id' => $first['id'],
            'body' => 'Hola Bruno, ¿tienes un minuto?',
        ])->assertStatus(201);

        $tenantA->run(function () use ($bruno): void {
            $notified = NotificationUser::where('user_id', $bruno->id)
                ->whereHas('notification', fn ($query) => $query->where('title', 'Nuevo mensaje directo'))
                ->exists();
            $this->assertTrue($notified, 'The 1:1 recipient must receive the direct-message notification');
        });
    }

    // =========================================================================
    // auth chain — chat resources reject deactivated accounts (authActive)
    // =========================================================================

    public function test_deactivated_account_cannot_read_or_search_chat(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        // Active user reads fine; same user once deactivated is blocked (403) by
        // the authActive middleware on the chat resources.
        $user = $this->makeUser($tenant);
        $activeToken = $this->residentToken($user);

        $this->tenantRequest($activeToken, $tenant)->getJson('/api/chat_channel')->assertStatus(200);
        $this->tenantRequest($activeToken, $tenant)->getJson('/api/chat_message')->assertStatus(200);

        $tenant->run(static function () use ($user): void {
            $user->forceFill(['auth_active' => false])->save();
        });

        $deactivatedToken = $this->residentToken($user);
        $this->tenantRequest($deactivatedToken, $tenant)->getJson('/api/chat_channel')->assertStatus(403);
        $this->tenantRequest($deactivatedToken, $tenant)->getJson('/api/chat_message')->assertStatus(403);
        $this->tenantRequest($deactivatedToken, $tenant)->postJson('/api/chat_message', [
            'chat_channel_id' => $this->channelId($tenant, 'General'),
            'body' => 'No debería poder',
        ])->assertStatus(403);
    }
}
