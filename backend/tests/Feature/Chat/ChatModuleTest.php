<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Chat\Models\ChatChannel;
use Modules\Chat\Models\ChatChannelMember;
use Modules\Chat\Models\ChatChannelType;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatMessageReport;
use Modules\Chat\Models\ChatReaction;
use Modules\Chat\Services\ChatService;
use Modules\Tenant\Models\Tenant;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Integration tests for Module 9 — Chat Comunitario (Community Chat).
 *
 * Flows covered:
 *  A. Chat channel type catalog seeded per tenant
 *  B. System channels created per tenant (Avisos oficiales + General, is_system=true)
 *  C. System channel cannot be deleted (guard enforced in service)
 *  D. Messages: hidden (soft-hide) not deleted; never soft-deleted
 *  E. Threading via reply_to_id
 *  F. Reactions: unique per user+emoji per message
 *  G. Reports: creates pending record
 *  H. Rate limit: max 30 messages/min/user throws exception
 *  I. Tenant isolation on all tables
 */
final class ChatModuleTest extends TestCase
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
    // A. Catalog seeding per tenant
    // =========================================================================

    public function test_chat_channel_type_catalog_seeded_per_tenant(): void
    {
        $tenant = $this->makeTenant('Catalog Chat Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $this->assertSame(4, ChatChannelType::count(), 'Must have 4 chat channel types');

            $this->assertNotNull(ChatChannelType::find(ChatChannelType::OFFICIAL));
            $this->assertNotNull(ChatChannelType::find(ChatChannelType::GENERAL));
            $this->assertNotNull(ChatChannelType::find(ChatChannelType::CUSTOM));
            $this->assertNotNull(ChatChannelType::find(ChatChannelType::DIRECT));
        });
    }

    // =========================================================================
    // B. System channels created per tenant (Avisos oficiales + General)
    // =========================================================================

    public function test_system_channels_created_on_tenant_seed(): void
    {
        $tenant = $this->makeTenant('System Channels Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $oficial = ChatChannel::where('name', 'Avisos oficiales')->first();
            $this->assertNotNull($oficial, '"Avisos oficiales" channel must exist');
            $this->assertTrue((bool) $oficial->is_system, '"Avisos oficiales" must be a system channel');
            $this->assertSame('board', $oficial->write_target_type, '"Avisos oficiales" must be board-write (only the board posts)');

            $general = ChatChannel::where('name', 'General')->first();
            $this->assertNotNull($general, '"General" channel must exist');
            $this->assertTrue((bool) $general->is_system, '"General" must be a system channel');
            $this->assertSame('all', $general->write_target_type, '"General" must be open for everyone to write');
        });
    }

    // =========================================================================
    // FIX B3 — all FOUR system channels are created (spec §13.2)
    // =========================================================================

    public function test_all_four_system_channels_created_and_undeletable(): void
    {
        $tenant = $this->makeTenant('Four Channels Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $expected = [
                'Avisos oficiales' => 'board',
                'General' => 'all',
                'Mesa directiva' => 'board',
                'Reportes y sugerencias' => 'all',
            ];

            $systemChannels = ChatChannel::where('is_system', true)->get();
            $this->assertCount(4, $systemChannels, 'Spec §13.2 requires 4 system channels');

            foreach ($expected as $name => $writeTargetType) {
                $channel = ChatChannel::where('name', $name)->first();
                $this->assertNotNull($channel, "\"{$name}\" channel must exist");
                $this->assertTrue((bool) $channel->is_system, "\"{$name}\" must be a system (undeletable) channel");
                $this->assertSame($writeTargetType, $channel->write_target_type, "\"{$name}\" must have write target {$writeTargetType}");
            }
        });
    }

    public function test_system_channels_idempotent_on_double_seed(): void
    {
        $tenant = $this->makeTenant('Idempotent Channels Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            // Call createSystemChannels twice — must not create duplicates
            $service = app(ChatService::class);
            $service->createSystemChannels();
            $service->createSystemChannels();

            foreach (['Avisos oficiales', 'General', 'Mesa directiva', 'Reportes y sugerencias'] as $name) {
                $this->assertSame(1, ChatChannel::where('name', $name)->count(), "Must not duplicate {$name}");
            }

            $this->assertSame(4, ChatChannel::where('is_system', true)->count(), 'Exactly 4 system channels');
        });
    }

    // =========================================================================
    // C. System channel cannot be deleted (exercised through the REAL HTTP route)
    // =========================================================================

    public function test_system_channel_cannot_be_deleted(): void
    {
        $tenant = $this->makeTenant('Delete Guard Tenant');
        $this->seedTenant($tenant);

        $admin = User::create([
            'name' => 'Admin Delete Guard',
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);
        $admin->tenants()->attach($tenant->id);
        $token = $admin->createToken('admin', ['*'])->plainTextToken;

        [$systemChannelId, $customChannelId] = $tenant->run(function (): array {
            $oficial = ChatChannel::where('name', 'Avisos oficiales')->firstOrFail();
            $this->assertTrue((bool) $oficial->is_system, 'Pre-condition: is_system must be true');

            $custom = ChatChannel::create([
                'name' => 'Canal borrable',
                'chat_channel_type_id' => ChatChannelType::CUSTOM,
                'target_type' => 'all',
                'is_moderated' => false,
                'is_system' => false,
                'is_archived' => false,
            ]);

            return [$oficial->id, $custom->id];
        });

        $this->app['auth']->forgetGuards();
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        // DELETE on a system channel is rejected by performDestroy.
        $this->withToken($token)->withHeader('X-Tenant-Id', $tenant->id)
            ->deleteJson("/api/chat_channel/{$systemChannelId}")
            ->assertStatus(422);

        // A non-system channel CAN be deleted (guard is specific, not blanket).
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->withHeader('X-Tenant-Id', $tenant->id)
            ->deleteJson("/api/chat_channel/{$customChannelId}")
            ->assertStatus(200);

        $tenant->run(function () use ($systemChannelId, $customChannelId): void {
            $this->assertDatabaseHas('chat_channel', [
                'id' => $systemChannelId,
                'deleted_at' => null,
            ]);
            $this->assertNotNull(
                ChatChannel::withTrashed()->find($customChannelId)?->deleted_at,
                'Custom channel must be soft-deleted'
            );
        });
    }

    // =========================================================================
    // D. Messages: hidden (soft-hide) not deleted
    // =========================================================================

    public function test_hide_message_sets_hidden_flag_without_deleting(): void
    {
        $tenant = $this->makeTenant('Hide Message Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $channel = ChatChannel::where('name', 'General')->firstOrFail();
            $userId = (string) Str::uuid();

            $service = app(ChatService::class);
            $service->joinChannel($channel, $userId);

            // Clear rate limit cache for clean test
            Cache::forget("chat_rate:{$channel->tenant_id}:{$userId}");

            $message = $service->postMessage($channel, $userId, 'Mensaje de prueba');
            $messageId = $message->id;

            $this->assertFalse((bool) $message->hidden, 'Pre-condition: message must not be hidden');

            $service->hideMessage($message);

            // Row must still exist
            $found = ChatMessage::withTrashed()->find($messageId);
            $this->assertNotNull($found, 'Hidden message must still exist in DB');
            $this->assertNull($found->deleted_at, 'Hidden message must NOT be soft-deleted');
            $this->assertTrue((bool) $found->hidden, 'hidden flag must be true after hide');
        });
    }

    // =========================================================================
    // E. Threading via reply_to_id
    // =========================================================================

    public function test_message_threading_via_reply_to_id(): void
    {
        $tenant = $this->makeTenant('Threading Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $channel = ChatChannel::where('name', 'General')->firstOrFail();
            $userId1 = (string) Str::uuid();
            $userId2 = (string) Str::uuid();

            $service = app(ChatService::class);
            $service->joinChannel($channel, $userId1);
            $service->joinChannel($channel, $userId2);

            Cache::forget("chat_rate:{$channel->tenant_id}:{$userId1}");
            Cache::forget("chat_rate:{$channel->tenant_id}:{$userId2}");

            $parent = $service->postMessage($channel, $userId1, 'Mensaje original');

            $reply = $service->postMessage($channel, $userId2, 'Respuesta al mensaje', $parent->id);

            $this->assertSame($parent->id, $reply->reply_to_id, 'Reply must reference parent via reply_to_id');

            // Load relationship
            $reply->load('replyTo');
            $this->assertNotNull($reply->replyTo, 'replyTo relation must load the parent message');
            $this->assertSame('Mensaje original', $reply->replyTo->body);
        });
    }

    // =========================================================================
    // F. Reactions: unique per user+emoji per message
    // =========================================================================

    public function test_reactions_unique_per_user_emoji_message(): void
    {
        $tenant = $this->makeTenant('Reaction Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $channel = ChatChannel::where('name', 'General')->firstOrFail();
            $userId = (string) Str::uuid();

            $service = app(ChatService::class);
            $service->joinChannel($channel, $userId);
            Cache::forget("chat_rate:{$channel->tenant_id}:{$userId}");

            $message = $service->postMessage($channel, $userId, 'Mensaje con reacción');

            $service->react($message, $userId, '👍');
            $service->react($message, $userId, '👍'); // duplicate — should be idempotent

            $this->assertSame(1, ChatReaction::where('chat_message_id', $message->id)
                ->where('user_id', $userId)
                ->where('emoji', '👍')
                ->count(), 'Duplicate reaction must be idempotent');
        });
    }

    public function test_multiple_users_can_react_with_same_emoji(): void
    {
        $tenant = $this->makeTenant('Multi React Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $channel = ChatChannel::where('name', 'General')->firstOrFail();
            $userId1 = (string) Str::uuid();
            $userId2 = (string) Str::uuid();
            $userId3 = (string) Str::uuid();

            $service = app(ChatService::class);
            foreach ([$userId1, $userId2, $userId3] as $uid) {
                $service->joinChannel($channel, $uid);
                Cache::forget("chat_rate:{$channel->tenant_id}:{$uid}");
            }

            $message = $service->postMessage($channel, $userId1, 'Reacciones múltiples');

            $service->react($message, $userId1, '👍');
            $service->react($message, $userId2, '👍');
            $service->react($message, $userId3, '👍');

            $this->assertSame(3, ChatReaction::where('chat_message_id', $message->id)->count());
        });
    }

    public function test_unreact_removes_reaction(): void
    {
        $tenant = $this->makeTenant('Unreact Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $channel = ChatChannel::where('name', 'General')->firstOrFail();
            $userId = (string) Str::uuid();

            $service = app(ChatService::class);
            $service->joinChannel($channel, $userId);
            Cache::forget("chat_rate:{$channel->tenant_id}:{$userId}");

            $message = $service->postMessage($channel, $userId, 'Mensaje para unreact');

            $service->react($message, $userId, '❤️');
            $this->assertSame(1, ChatReaction::where('chat_message_id', $message->id)->count());

            $service->unreact($message, $userId, '❤️');
            $this->assertSame(0, ChatReaction::where('chat_message_id', $message->id)->count());
        });
    }

    // =========================================================================
    // G. Reports: creates pending record
    // =========================================================================

    public function test_report_creates_pending_record(): void
    {
        $tenant = $this->makeTenant('Report Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $channel = ChatChannel::where('name', 'General')->firstOrFail();
            $authorId = (string) Str::uuid();
            $reporterId = (string) Str::uuid();

            $service = app(ChatService::class);
            $service->joinChannel($channel, $authorId);
            $service->joinChannel($channel, $reporterId);
            Cache::forget("chat_rate:{$channel->tenant_id}:{$authorId}");

            $message = $service->postMessage($channel, $authorId, 'Mensaje ofensivo');

            $report = $service->report($message, $reporterId, 'Contenido inapropiado');

            $this->assertNotNull($report->id, 'Report must be created');
            $this->assertSame($message->id, $report->chat_message_id);
            $this->assertSame($reporterId, $report->reporter_user_id);
            $this->assertSame(ChatMessageReport::STATUS_PENDING, $report->status, 'Report status must be pending');
            $this->assertSame('Contenido inapropiado', $report->reason);
        });
    }

    // =========================================================================
    // H. Rate limit: max 30 messages/min/user
    // =========================================================================

    public function test_rate_limit_throws_after_30_messages(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Rate limit exceeded/');

        $tenant = $this->makeTenant('Rate Limit Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $channel = ChatChannel::where('name', 'General')->firstOrFail();
            $userId = (string) Str::uuid();

            // Clear rate limit cache for clean test
            Cache::forget("chat_rate:{$channel->tenant_id}:{$userId}");

            $service = app(ChatService::class);
            $service->joinChannel($channel, $userId);

            // Send exactly 30 messages — should all succeed
            for ($i = 0; $i < 30; $i++) {
                $service->postMessage($channel, $userId, "Mensaje {$i}");
            }

            // 31st message must throw
            $service->postMessage($channel, $userId, 'Mensaje que supera el límite');
        });
    }

    // =========================================================================
    // I. Tenant isolation
    // =========================================================================

    public function test_tenant_isolation_chat_channel(): void
    {
        $tenant1 = $this->makeTenant('Chat Isolation T1');
        $tenant2 = $this->makeTenant('Chat Isolation T2');
        $this->seedTenant($tenant1);
        $this->seedTenant($tenant2);

        $tenant1->run(function (): void {
            // T1 has system channels
            $this->assertGreaterThan(0, ChatChannel::count());
        });

        $tenant2->run(function (): void {
            // T2 also has its own system channels (not T1's)
            $this->assertGreaterThan(0, ChatChannel::count());
        });
    }

    public function test_tenant_isolation_chat_message(): void
    {
        $tenant1 = $this->makeTenant('Message Isolation T1');
        $tenant2 = $this->makeTenant('Message Isolation T2');
        $this->seedTenant($tenant1);
        $this->seedTenant($tenant2);

        $tenant1->run(function (): void {
            $channel = ChatChannel::where('name', 'General')->firstOrFail();
            $userId = (string) Str::uuid();

            $service = app(ChatService::class);
            $service->joinChannel($channel, $userId);
            Cache::forget("chat_rate:{$channel->tenant_id}:{$userId}");

            $service->postMessage($channel, $userId, 'Mensaje en T1');
        });

        $tenant2->run(function (): void {
            $this->assertSame(0, ChatMessage::count(), 'Tenant 2 must not see Tenant 1 messages');
        });
    }

    public function test_tenant_isolation_chat_reaction(): void
    {
        $tenant1 = $this->makeTenant('Reaction Isolation T1');
        $tenant2 = $this->makeTenant('Reaction Isolation T2');
        $this->seedTenant($tenant1);
        $this->seedTenant($tenant2);

        $tenant1->run(function (): void {
            $channel = ChatChannel::where('name', 'General')->firstOrFail();
            $userId = (string) Str::uuid();

            $service = app(ChatService::class);
            $service->joinChannel($channel, $userId);
            Cache::forget("chat_rate:{$channel->tenant_id}:{$userId}");

            $message = $service->postMessage($channel, $userId, 'Mensaje con reacción');
            $service->react($message, $userId, '👍');
        });

        $tenant2->run(function (): void {
            $this->assertSame(0, ChatReaction::count(), 'Tenant 2 must not see Tenant 1 reactions');
        });
    }

    public function test_tenant_isolation_chat_channel_member(): void
    {
        $tenant1 = $this->makeTenant('Member Isolation T1');
        $tenant2 = $this->makeTenant('Member Isolation T2');
        $this->seedTenant($tenant1);
        $this->seedTenant($tenant2);

        $tenant1->run(function (): void {
            $channel = ChatChannel::where('name', 'General')->firstOrFail();
            $userId = (string) Str::uuid();

            $service = app(ChatService::class);
            $service->joinChannel($channel, $userId);
        });

        $tenant2->run(function (): void {
            $this->assertSame(0, ChatChannelMember::count(), 'Tenant 2 must not see Tenant 1 channel members');
        });
    }
}
