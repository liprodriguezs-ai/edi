<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

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
use Modules\Chat\Models\ChatChannel;
use Modules\Chat\Services\ChatService;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * chat-file-attachments Phase 1 — MediaAuthorizationService::ownsMediaSubject()
 * ChatMessage branch (design ADR-2). Fixes the pre-existing defect where every
 * real (non-wildcard) chat-media download 403'd, because ChatMessage had no
 * ownership rule at all.
 *
 * Covers spec `media-authorization`:
 *  - a channel member downloads a ChatMessage-owned media → authorized
 *  - a non-member → 403
 *  - a moderator (admin role) → authorized without channel membership
 *  - cross-tenant ChatMessage owner → 403 (tenant isolation), independent of
 *    the channel-access outcome
 */
final class MediaAuthorizationServiceTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('private');
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

    private function makeUser(Tenant $tenant, string $name = 'Vecino'): User
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
     * A real (non-wildcard) resident token — the exact scenario that 403'd
     * before this fix, since MEDIA_READ_ABILITIES has no ChatMessage entry.
     */
    private function residentToken(User $user): string
    {
        return $user->createToken('resident', ['read.chat_message'])->plainTextToken;
    }

    private function grantAdministratorRole(User $user): void
    {
        AppRole::firstOrCreate(['name' => 'administrator', 'guard_name' => 'api']);
        $user->assignRole('administrator');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

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

    private function postImageMessage(ChatChannel $channel, string $authorId): string
    {
        $message = app(ChatService::class)->postMessage(
            $channel,
            $authorId,
            'con imagen',
            null,
            null,
            [['file' => UploadedFile::fake()->image('foto.png', 80, 80), 'title' => null]],
        );

        return $message->getMedia('attachments')->first()->id;
    }

    // =========================================================================
    // A. Channel member downloads own-channel media (the regression fix)
    // =========================================================================

    public function test_channel_member_can_download_chat_media(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $author = $this->makeUser($tenant, 'Autor');

        $mediaId = $tenant->run(function () use ($author): string {
            $channel = ChatChannel::where('name', 'General')->firstOrFail();

            return $this->postImageMessage($channel, $author->id);
        });

        $this->tenantRequest($this->residentToken($author), $tenant)
            ->getJson("/api/media/{$mediaId}/download-url")
            ->assertStatus(200)
            ->assertJsonStructure(['download_url', 'expires_in', 'strategy']);
    }

    // =========================================================================
    // B. Non-member is denied
    // =========================================================================

    public function test_non_member_is_denied(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'Presidenta');
        $this->makeCurrentBoardMember($tenant, $president);
        $outsider = $this->makeUser($tenant, 'Externo');

        $mediaId = $tenant->run(function () use ($president): string {
            $channel = ChatChannel::where('name', 'Mesa directiva')->firstOrFail();

            return $this->postImageMessage($channel, $president->id);
        });

        $this->tenantRequest($this->residentToken($outsider), $tenant)
            ->getJson("/api/media/{$mediaId}/download-url")
            ->assertStatus(403);
    }

    // =========================================================================
    // C. Moderator (admin role) downloads without channel membership
    // =========================================================================

    public function test_moderator_can_download_without_channel_membership(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'Presidenta');
        $this->makeCurrentBoardMember($tenant, $president);
        $admin = $this->makeUser($tenant, 'Admin');
        $this->grantAdministratorRole($admin);

        $mediaId = $tenant->run(function () use ($president): string {
            $channel = ChatChannel::where('name', 'Mesa directiva')->firstOrFail();

            return $this->postImageMessage($channel, $president->id);
        });

        $this->tenantRequest($this->residentToken($admin), $tenant)
            ->getJson("/api/media/{$mediaId}/download-url")
            ->assertStatus(200);
    }

    // =========================================================================
    // D. Tenant isolation — cross-tenant download denied regardless of access
    // =========================================================================

    public function test_cross_tenant_download_is_denied(): void
    {
        $tenantA = $this->makeTenant('Media Auth A');
        $tenantB = $this->makeTenant('Media Auth B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $authorA = $this->makeUser($tenantA, 'Autor A');
        $userB = $this->makeUser($tenantB, 'Usuario B');

        $mediaId = $tenantA->run(function () use ($authorA): string {
            $channel = ChatChannel::where('name', 'General')->firstOrFail();

            return $this->postImageMessage($channel, $authorA->id);
        });

        $this->tenantRequest($this->residentToken($userB), $tenantB)
            ->getJson("/api/media/{$mediaId}/download-url")
            ->assertStatus(403);
    }
}
