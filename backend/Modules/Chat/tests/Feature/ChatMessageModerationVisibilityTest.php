<?php

declare(strict_types=1);

namespace Modules\Chat\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Chat\Http\Controllers\ChatMessageController;
use Modules\Chat\Models\ChatChannel;
use Modules\Chat\Services\ChatService;
use Modules\Tenant\Models\Tenant;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Regression coverage for plan 049 — hidden message bodies must stay redacted
 * even when read through the `replyTo` relation.
 *
 * `replyTo` is an allow-listed include on the resident-facing chat_message
 * resource (ChatMessageController::includes()) and is a self-reference to
 * ChatMessage, so it carries the same body/body_html fields as any top-level
 * message. Without recursing the redaction into the loaded replyTo relation,
 * a resident could read the full text of a hidden message by requesting
 * include=replyTo on a message that quoted it — defeating the "hidden, never
 * shown to non-moderators" invariant (spec §13.4.4) with a single query
 * parameter.
 */
final class ChatMessageModerationVisibilityTest extends TestCase
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
    // Helpers (mirrors Tests\Feature\Chat\ChatHttpEnforcementTest)
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
    // Case (a) + (b): replyTo pointing at a HIDDEN message
    // =========================================================================

    public function test_replyto_hidden_parent_body_is_redacted_for_non_moderator(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $author = $this->makeUser($tenant, 'Autora');
        $reader = $this->makeUser($tenant, 'Lector');
        $admin = $this->makeUser($tenant, 'Admin');
        $generalId = $this->channelId($tenant, 'General');

        $this->joinViaService($tenant, $generalId, $author->id);
        $this->joinViaService($tenant, $generalId, $reader->id);

        // Message A will be hidden; message B quotes it via reply_to_id.
        [$messageAId, $messageBId] = $tenant->run(static function () use ($generalId, $author): array {
            $service = app(ChatService::class);
            $channel = ChatChannel::findOrFail($generalId);

            $messageA = $service->postMessage($channel, $author->id, 'Contenido ofensivo original');
            $messageB = $service->postMessage($channel, $author->id, 'Cito el mensaje anterior', $messageA->id);

            return [$messageA->id, $messageB->id];
        });

        $adminToken = $this->adminToken($admin);
        $this->tenantRequest($adminToken, $tenant)
            ->postJson("/api/chat-message/{$messageAId}/hide")
            ->assertStatus(200);

        // Non-moderator reader requests message B WITH its replyTo relation:
        // the quoted hidden parent must come back redacted, never the real text.
        $this->tenantRequest($this->residentToken($reader), $tenant)
            ->getJson("/api/chat_message/{$messageBId}?include=replyTo")
            ->assertStatus(200)
            ->assertJsonPath('data.body', 'Cito el mensaje anterior')
            ->assertJsonPath('data.reply_to.body', ChatMessageController::HIDDEN_BODY_PLACEHOLDER)
            ->assertJsonPath('data.reply_to.body_html', null);

        // A moderator keeps full audit access, including through replyTo.
        $this->tenantRequest($adminToken, $tenant)
            ->getJson("/api/chat_message/{$messageBId}?include=replyTo")
            ->assertStatus(200)
            ->assertJsonPath('data.reply_to.body', 'Contenido ofensivo original');
    }

    // =========================================================================
    // Case (c): sanity — a non-hidden replyTo parent is untouched
    // =========================================================================

    public function test_replyto_non_hidden_parent_body_is_returned_normally(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $author = $this->makeUser($tenant, 'Autora');
        $reader = $this->makeUser($tenant, 'Lector');
        $generalId = $this->channelId($tenant, 'General');

        $this->joinViaService($tenant, $generalId, $author->id);
        $this->joinViaService($tenant, $generalId, $reader->id);

        [, $messageBId] = $tenant->run(static function () use ($generalId, $author): array {
            $service = app(ChatService::class);
            $channel = ChatChannel::findOrFail($generalId);

            $messageA = $service->postMessage($channel, $author->id, 'Mensaje visible, nunca ocultado');
            $messageB = $service->postMessage($channel, $author->id, 'Respondo al mensaje visible', $messageA->id);

            return [$messageA->id, $messageB->id];
        });

        $this->tenantRequest($this->residentToken($reader), $tenant)
            ->getJson("/api/chat_message/{$messageBId}?include=replyTo")
            ->assertStatus(200)
            ->assertJsonPath('data.reply_to.body', 'Mensaje visible, nunca ocultado');
    }
}
