<?php

declare(strict_types=1);

namespace Tests\Feature\Announcements;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Announcements\Models\Announcement;
use Modules\Announcements\Models\AnnouncementPriority;
use Modules\Announcements\Models\AnnouncementType;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Feature tests for announcement attachment removal:
 *  DELETE /api/announcement/{id}/media/{mediaId}
 *
 * Covers the author happy path (upload → delete → collection empty) and the
 * ownership guard (a non-author manager cannot delete another author's attachment).
 */
final class AnnouncementMediaTest extends TestCase
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

        config(['notifications.channels' => ['log']]);

        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('private');
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

    private function makeUser(Tenant $tenant, string $name = 'User'): User
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

    /** @param array<string> $abilities */
    private function creatorToken(User $user, array $abilities = [
        'read.announcement',
        'create.announcement',
        'update.announcement',
        'delete.announcement',
    ]): string
    {
        return $user->createToken('creator', $abilities)->plainTextToken;
    }

    /** @return array<string, mixed> */
    private function announcementPayload(Tenant $tenant, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Aviso '.Str::random(6),
            'content' => 'Contenido del aviso de prueba.',
            'announcement_type_id' => Catalog::id(AnnouncementType::class, 'Aviso general'),
            'announcement_priority_id' => AnnouncementPriority::NORMAL,
            'target_type' => 'all',
        ], $overrides);
    }

    private function fakePdfFile(string $name = 'adjunto.pdf'): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'ann_media_test_');
        $this->tempFiles[] = $tmpPath;
        file_put_contents($tmpPath, str_repeat('%PDF-1.4 announcement attachment ', 50));

        return new UploadedFile($tmpPath, $name, 'application/pdf', null, true);
    }

    public function test_author_can_delete_an_announcement_attachment(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');

        $ann = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/announcement', $this->announcementPayload($tenant))
            ->assertStatus(201)
            ->json('data');

        $mediaId = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->post("/api/announcement/{$ann['id']}/media", ['file' => $this->fakePdfFile('ok.pdf')])
            ->assertStatus(201)
            ->json('data.id');

        $tenant->run(function () use ($ann): void {
            $this->assertSame(1, Announcement::findOrFail($ann['id'])->getMedia('attachments')->count());
        });

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->deleteJson("/api/announcement/{$ann['id']}/media/{$mediaId}")
            ->assertStatus(200)
            ->assertJsonPath('data', true);

        $tenant->run(function () use ($ann): void {
            $this->assertSame(
                0,
                Announcement::findOrFail($ann['id'])->getMedia('attachments')->count(),
                'The attachment must be removed'
            );
        });
    }

    public function test_deleting_media_is_forbidden_for_a_non_author_manager(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $author = $this->makeUser($tenant, 'Autor');
        $colleague = $this->makeUser($tenant, 'Colega');

        $ann = $this->tenantRequest($this->creatorToken($author), $tenant)
            ->postJson('/api/announcement', $this->announcementPayload($tenant))
            ->assertStatus(201)
            ->json('data');

        $mediaId = $this->tenantRequest($this->creatorToken($author), $tenant)
            ->post("/api/announcement/{$ann['id']}/media", ['file' => $this->fakePdfFile('ok.pdf')])
            ->assertStatus(201)
            ->json('data.id');

        // A non-author manager (has update.announcement) must NOT delete the attachment.
        $this->tenantRequest($this->creatorToken($colleague), $tenant)
            ->deleteJson("/api/announcement/{$ann['id']}/media/{$mediaId}")
            ->assertStatus(403);

        $tenant->run(function () use ($ann): void {
            $this->assertSame(
                1,
                Announcement::findOrFail($ann['id'])->getMedia('attachments')->count(),
                'The attachment must remain'
            );
        });
    }
}
