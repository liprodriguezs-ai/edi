<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Chat\Models\ChatChannel;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Services\ChatService;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Notification\Models\NotificationUser;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Fase 4 — rich text + image attachments on chat messages.
 *  - body_html is sanitized server-side (XSS allowlist; scripts/handlers/js: stripped).
 *  - body (plain text) is derived from the sanitized HTML.
 *  - images attach to the 'attachments' collection and convert to WebP (GD).
 *  - notifications ALWAYS use the plain body, never the HTML.
 */
final class ChatRichMessageTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    /** @var array<string> */
    private array $tempFiles = [];

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

    /** @param array<string> $abilities */
    private function residentToken(User $user, array $abilities = [
        'read.chat_channel',
        'read.chat_message',
        'create.chat_message',
    ]): string
    {
        return $user->createToken('resident', $abilities)->plainTextToken;
    }

    /**
     * Real-content temp file (not UploadedFile::fake()->create(), which produces
     * zero-byte files whose MIME is application/x-empty — rejected by both
     * Spatie's acceptsMimeTypes and Laravel's `mimes:` rule).
     */
    private function fakePdfFile(string $name = 'adjunto.pdf', int $sizeKb = 100): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'chat_attach_test_');
        $this->tempFiles[] = $tmpPath;
        file_put_contents($tmpPath, str_repeat('%PDF-1.4 chat attachment test content ', max(1, intdiv($sizeKb * 1024, 39))));

        return new UploadedFile($tmpPath, $name, 'application/pdf', null, true);
    }

    private function makeResident(Tenant $tenant, User $user, Tower $tower): void
    {
        $tenant->run(static function () use ($user, $tower): void {
            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Departamento'),
                'number' => (string) mt_rand(1, 99999),
                'tower_id' => $tower->id,
                'floor' => '1',
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);

            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
                'is_billing_owner' => true,
                'start_date' => now()->toDateString(),
            ]);
        });
    }

    public function test_body_html_is_sanitized_and_plain_body_is_derived(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $author = $this->makeUser($tenant, 'Autor');

        $tenant->run(function () use ($author): void {
            $channel = ChatChannel::where('name', 'General')->firstOrFail();

            $dirty = '<p>Hola <b>mundo</b><script>alert(1)</script>'
                .'<img src=x onerror="alert(2)">'
                .'<a href="javascript:alert(3)">malo</a> '
                .'<a href="https://vecinia.test">bueno</a></p>';

            $message = app(ChatService::class)->postMessage($channel, $author->id, '', null, $dirty);

            // XSS stripped.
            $this->assertStringNotContainsStringIgnoringCase('<script', (string) $message->body_html);
            $this->assertStringNotContainsStringIgnoringCase('onerror', (string) $message->body_html);
            $this->assertStringNotContainsStringIgnoringCase('javascript:', (string) $message->body_html);
            // Formatting + safe link kept.
            $this->assertStringContainsString('<b>mundo</b>', (string) $message->body_html);
            $this->assertStringContainsString('https://vecinia.test', (string) $message->body_html);
            // Plain body derived (no markup), used for notifications/preview.
            $this->assertStringNotContainsString('<', (string) $message->body);
            $this->assertStringContainsString('Hola mundo', (string) $message->body);
        });
    }

    public function test_image_attachment_is_stored_and_converted_to_webp(): void
    {
        Storage::fake('public');

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $author = $this->makeUser($tenant, 'Autor');

        $tenant->run(function () use ($author): void {
            $channel = ChatChannel::where('name', 'General')->firstOrFail();

            $message = app(ChatService::class)->postMessage(
                $channel,
                $author->id,
                'con imagen',
                null,
                null,
                [['file' => UploadedFile::fake()->image('foto.png', 120, 120), 'title' => 'Mi foto']],
            );

            $media = $message->getMedia('attachments');
            $this->assertCount(1, $media, 'The image must be attached to the attachments collection');
            // The frontend bubble reads the title from custom_properties.title.
            $this->assertSame('Mi foto', $media->first()->getCustomProperty('title'), 'The title is stored in custom_properties');
            $this->assertSame('Mi foto', $media->first()->name, 'The title is also the media name');
            $this->assertTrue(
                $media->first()->hasGeneratedConversion('webp'),
                'GD must generate the WebP conversion of the attachment'
            );
        });
    }

    public function test_notification_uses_plain_text_not_html(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $author = $this->makeUser($tenant, 'Autor');
        $listener = $this->makeUser($tenant, 'Oyente');
        $tower = $this->makeTower($tenant);
        $this->makeResident($tenant, $author, $tower);
        $this->makeResident($tenant, $listener, $tower);

        $tenant->run(function () use ($author, $listener): void {
            $channel = ChatChannel::where('name', 'General')->firstOrFail();

            app(ChatService::class)->postMessage(
                $channel,
                $author->id,
                '',
                null,
                '<p>Aviso <b>importante</b> con <i>formato</i></p>',
            );

            $row = NotificationUser::where('user_id', $listener->id)
                ->whereHas('notification', fn ($q) => $q->where('title', 'like', 'Nuevo mensaje%'))
                ->with('notification')
                ->first();

            $this->assertNotNull($row, 'The resident audience must be notified');
            $message = (string) $row->notification->message;
            $this->assertStringNotContainsString('<', $message, 'The notification preview must be plain text, not HTML');
            $this->assertStringContainsString('Aviso importante con formato', $message);
        });
    }

    private function makeTower(Tenant $tenant): Tower
    {
        return $tenant->run(static fn (): Tower => Tower::create(['name' => 'Torre '.Str::random(4), 'floors_count' => 3]));
    }

    // =========================================================================
    // chat-file-attachments Phase 2 — generalization to the platform-wide
    // whitelist (spec `chat-attachments`).
    // =========================================================================

    public function test_whitelist_matrix_persists_mixed_valid_attachments(): void
    {
        Storage::fake('public');

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $author = $this->makeUser($tenant, 'Autor');
        $generalId = $tenant->run(static fn (): string => ChatChannel::where('name', 'General')->firstOrFail()->id);

        $created = $this->tenantRequest($this->residentToken($author), $tenant)
            ->post('/api/chat_message', [
                'chat_channel_id' => $generalId,
                'body' => 'Con adjuntos mixtos',
                'attachments' => [
                    $this->fakePdfFile('doc1.pdf', 2 * 1024),
                    $this->fakePdfFile('doc2.pdf', 1 * 1024),
                    UploadedFile::fake()->image('foto.jpg', 100, 100),
                ],
            ])
            ->assertStatus(201)
            ->json('data');

        $tenant->run(function () use ($created): void {
            $message = ChatMessage::findOrFail($created['id']);
            $this->assertSame(3, $message->getMedia('attachments')->count(), 'All 3 whitelisted attachments must be persisted');
        });
    }

    public function test_disallowed_extension_is_rejected_with_no_media_persisted(): void
    {
        Storage::fake('public');

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $author = $this->makeUser($tenant, 'Autor');
        $generalId = $tenant->run(static fn (): string => ChatChannel::where('name', 'General')->firstOrFail()->id);

        $this->tenantRequest($this->residentToken($author), $tenant)
            ->post('/api/chat_message', [
                'chat_channel_id' => $generalId,
                'body' => 'Con zip',
                'attachments' => [UploadedFile::fake()->create('malware.zip', 100, 'application/zip')],
            ])
            ->assertStatus(422);

        $tenant->run(function (): void {
            $this->assertSame(0, ChatMessage::count(), 'A rejected attachment must not create a message');
        });
    }

    public function test_oversize_attachment_is_rejected(): void
    {
        Storage::fake('public');

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $author = $this->makeUser($tenant, 'Autor');
        $generalId = $tenant->run(static fn (): string => ChatChannel::where('name', 'General')->firstOrFail()->id);

        $this->tenantRequest($this->residentToken($author), $tenant)
            ->post('/api/chat_message', [
                'chat_channel_id' => $generalId,
                'body' => 'Adjunto enorme',
                'attachments' => [$this->fakePdfFile('grande.pdf', 10241)],
            ])
            ->assertStatus(422);

        $tenant->run(function (): void {
            $this->assertSame(0, ChatMessage::count());
        });
    }

    public function test_too_many_attachments_is_rejected(): void
    {
        Storage::fake('public');

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $author = $this->makeUser($tenant, 'Autor');
        $generalId = $tenant->run(static fn (): string => ChatChannel::where('name', 'General')->firstOrFail()->id);

        $attachments = [];
        for ($i = 1; $i <= 6; $i++) {
            $attachments[] = $this->fakePdfFile("adjunto-{$i}.pdf");
        }

        $this->tenantRequest($this->residentToken($author), $tenant)
            ->post('/api/chat_message', [
                'chat_channel_id' => $generalId,
                'body' => 'Demasiados adjuntos',
                'attachments' => $attachments,
            ])
            ->assertStatus(422);

        $tenant->run(function (): void {
            $this->assertSame(0, ChatMessage::count(), 'The 6th file must reject the whole request, never silently drop/rotate');
        });
    }

    public function test_service_layer_rejects_disallowed_mime_bypassing_form_request(): void
    {
        Storage::fake('public');

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $author = $this->makeUser($tenant, 'Autor');

        $tmpPath = tempnam(sys_get_temp_dir(), 'chat_bad_mime_');
        $this->tempFiles[] = $tmpPath;
        file_put_contents($tmpPath, str_repeat('not a real archive', 50));
        $badFile = new UploadedFile($tmpPath, 'evil.exe', 'application/x-msdownload', null, true);

        $tenant->run(function () use ($author, $badFile): void {
            $channel = ChatChannel::where('name', 'General')->firstOrFail();

            $this->expectException(\InvalidArgumentException::class);

            app(ChatService::class)->postMessage(
                $channel,
                $author->id,
                'con archivo malicioso',
                null,
                null,
                [['file' => $badFile]],
            );
        });

        $this->assertSame(0, ChatMessage::count(), 'The service layer must reject before addMedia() persists anything');
    }

    public function test_pdf_attachment_produces_no_webp_conversion(): void
    {
        Storage::fake('public');

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $author = $this->makeUser($tenant, 'Autor');

        $tenant->run(function () use ($author): void {
            $channel = ChatChannel::where('name', 'General')->firstOrFail();

            $message = app(ChatService::class)->postMessage(
                $channel,
                $author->id,
                'con pdf',
                null,
                null,
                [['file' => $this->fakePdfFile('documento.pdf', 100)]],
            );

            $media = $message->getMedia('attachments');
            $this->assertCount(1, $media, 'The PDF must be persisted to the attachments collection');
            $this->assertFalse(
                $media->first()->hasGeneratedConversion('webp'),
                'A PDF must NOT be rasterized into a webp conversion (ADR-4)'
            );
        });
    }
}
