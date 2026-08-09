<?php

declare(strict_types=1);

namespace Tests\Feature\Announcements;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Announcements\Models\Announcement;
use Modules\Announcements\Models\AnnouncementPriority;
use Modules\Announcements\Models\AnnouncementStatus;
use Modules\Announcements\Models\AnnouncementType;
use Modules\Announcements\Services\AnnouncementService;
use Modules\Chat\Models\ChatChannel;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Services\ChatService;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Notification\Models\Notification;
use Modules\Notification\Models\NotificationUser;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Announcements → Chat cross-post (publish posts into "Avisos oficiales").
 *
 * Covers:
 *  - Publishing a draft creates exactly ONE chat_message in the official channel
 *    (correct author, body contains the title).
 *  - The cross-post does NOT duplicate notifications: only the announcement's
 *    own in-app rows exist, never a "Nuevo mensaje en ..." chat notification.
 *  - Calling publish() twice does not duplicate the chat message.
 *  - A scheduled announcement (future publish_at) does not post; the later
 *    effective publish posts exactly once.
 *  - A missing official channel never breaks publish().
 *  - chat-file-attachments: attachments are carried via Media::copy() onto the
 *    cross-posted message (owner = ChatMessage), a text-only announcement
 *    posts without media, and a copy failure never breaks publish.
 */
final class AnnouncementChatCrossPostTest extends TestCase
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
        Storage::fake('public');
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

    /**
     * Real-content temp file — UploadedFile::fake()->create() produces
     * zero-byte files rejected by Spatie's acceptsMimeTypes filter.
     */
    private function fakePdfFile(string $name = 'adjunto.pdf'): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'crosspost_attach_test_');
        $this->tempFiles[] = $tmpPath;
        file_put_contents($tmpPath, str_repeat('%PDF-1.4 cross-post attachment test content ', 50));

        return new UploadedFile($tmpPath, $name, 'application/pdf', null, true);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * User + tower + unit + active unit_user pivot, so the 'all' audience has a
     * real recipient and publish() creates real in-app notification rows.
     */
    private function makeResident(Tenant $tenant): User
    {
        $resident = $this->makeUser($tenant);

        $tenant->run(function () use ($resident): void {
            $tower = Tower::create(['name' => 'Torre '.Str::random(4), 'floors_count' => 3]);

            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Departamento'),
                'number' => (string) mt_rand(1, 99999),
                'tower_id' => $tower->id,
                'floor' => '1',
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);

            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $resident->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
                'is_billing_owner' => true,
                'start_date' => now()->toDateString(),
            ]);
        });

        return $resident;
    }

    /**
     * Draft announcement with a real author. Must run inside the tenant context.
     */
    private function buildAnnouncement(string $authorUserId, array $overrides = []): Announcement
    {
        return Announcement::create(array_merge([
            'title' => 'Aviso de prueba '.Str::random(6),
            'announcement_type_id' => Catalog::id(AnnouncementType::class, 'Aviso general'),
            'announcement_priority_id' => AnnouncementPriority::NORMAL,
            'announcement_status_id' => AnnouncementStatus::DRAFT,
            'content' => 'Contenido del aviso de prueba.',
            'target_type' => 'all',
            'target_selector' => null,
            'channel_push' => false,
            'channel_board' => true,
            'channel_email' => false,
            'channel_sms' => false,
            'author_user_id' => $authorUserId,
        ], $overrides));
    }

    private function officialChannel(): ChatChannel
    {
        $channel = app(ChatService::class)->findOfficialChannel();
        $this->assertNotNull($channel, 'Seeded tenant must have the "Avisos oficiales" system channel');

        return $channel;
    }

    // =========================================================================
    // A. Publish cross-posts exactly one message with the right author/body
    // =========================================================================

    public function test_publish_cross_posts_one_message_to_official_channel(): void
    {
        $tenant = $this->makeTenant('CrossPost Tenant');
        $this->seedTenant($tenant);
        $author = $this->makeUser($tenant);

        $tenant->run(function () use ($author): void {
            $announcement = $this->buildAnnouncement($author->id);

            app(AnnouncementService::class)->publish($announcement);

            $channel = $this->officialChannel();
            $messages = ChatMessage::where('chat_channel_id', $channel->id)->get();

            $this->assertCount(1, $messages, 'Publish must cross-post exactly ONE message to "Avisos oficiales"');
            $this->assertSame($author->id, $messages->first()->user_id, 'The chat message author must be the announcement author');
            $this->assertStringContainsString($announcement->title, $messages->first()->body, 'The chat message body must contain the announcement title');
        });
    }

    // =========================================================================
    // B. No duplicated notifications from the cross-post
    // =========================================================================

    public function test_cross_post_does_not_create_additional_chat_notifications(): void
    {
        $tenant = $this->makeTenant('NoDup Tenant');
        $this->seedTenant($tenant);
        $author = $this->makeUser($tenant);
        $resident = $this->makeResident($tenant);

        $tenant->run(function () use ($author, $resident): void {
            $announcement = $this->buildAnnouncement($author->id);

            app(AnnouncementService::class)->publish($announcement);

            // The chat message exists...
            $this->assertSame(1, ChatMessage::where('chat_channel_id', $this->officialChannel()->id)->count());

            // ...but the ONLY in-app notification is the announcement's own: one
            // Notification row (titled with the announcement title) and one
            // NotificationUser row for the resident. A chat-message notification
            // would have added a second Notification titled "Nuevo mensaje en ...".
            $this->assertSame(1, Notification::count(), 'Publish must create exactly ONE notification (the announcement, never the chat post)');
            $this->assertSame($announcement->title, Notification::first()->title);
            $this->assertSame(0, Notification::where('title', 'like', 'Nuevo mensaje%')->count(), 'No chat-message notification must be created by the cross-post');
            $this->assertSame(1, NotificationUser::where('user_id', $resident->id)->count(), 'The resident receives only the announcement notification');
        });
    }

    // =========================================================================
    // C. publish() twice does not duplicate the chat message
    // =========================================================================

    public function test_publishing_twice_does_not_duplicate_chat_message(): void
    {
        $tenant = $this->makeTenant('RePublish Tenant');
        $this->seedTenant($tenant);
        $author = $this->makeUser($tenant);

        $tenant->run(function () use ($author): void {
            $announcement = $this->buildAnnouncement($author->id);

            $service = app(AnnouncementService::class);
            $service->publish($announcement);
            $service->publish($announcement->fresh());

            $this->assertSame(
                1,
                ChatMessage::where('chat_channel_id', $this->officialChannel()->id)->count(),
                'Re-publishing an already-published announcement must NOT duplicate the chat message'
            );
        });
    }

    // =========================================================================
    // D. Scheduled announcement: no post while draft, one post on activation
    // =========================================================================

    public function test_scheduled_announcement_posts_only_when_effectively_published(): void
    {
        $tenant = $this->makeTenant('Scheduled Tenant');
        $this->seedTenant($tenant);
        $author = $this->makeUser($tenant);

        $tenant->run(function () use ($author): void {
            $announcement = $this->buildAnnouncement($author->id, [
                'publish_at' => now()->addDay(),
            ]);

            $service = app(AnnouncementService::class);
            $service->publish($announcement);

            $announcement->refresh();
            $this->assertSame(AnnouncementStatus::DRAFT, $announcement->announcement_status_id, 'Future publish_at must keep the announcement DRAFT');
            $this->assertSame(0, ChatMessage::where('chat_channel_id', $this->officialChannel()->id)->count(), 'A scheduled (still draft) announcement must NOT post to chat');

            // The cron flow re-calls publish() once publish_at is reached.
            $announcement->publish_at = now()->subMinute();
            $announcement->save();

            $service->publish($announcement->fresh());

            $announcement->refresh();
            $this->assertSame(AnnouncementStatus::PUBLISHED, $announcement->announcement_status_id);
            $this->assertSame(1, ChatMessage::where('chat_channel_id', $this->officialChannel()->id)->count(), 'The effective publish must cross-post exactly ONCE');
        });
    }

    // =========================================================================
    // E. Missing official channel never breaks publish()
    // =========================================================================

    public function test_publish_does_not_fail_when_official_channel_is_missing(): void
    {
        $tenant = $this->makeTenant('NoChannel Tenant');
        $this->seedTenant($tenant);
        $author = $this->makeUser($tenant);

        $tenant->run(function () use ($author): void {
            $this->officialChannel()->delete();

            $announcement = $this->buildAnnouncement($author->id);

            $published = app(AnnouncementService::class)->publish($announcement);

            $this->assertSame(AnnouncementStatus::PUBLISHED, $published->announcement_status_id, 'Publish must succeed even without the official chat channel');
            $this->assertSame(0, ChatMessage::count(), 'No chat message can be created without the official channel');
        });
    }

    // =========================================================================
    // F. chat-file-attachments — attachments are carried onto the cross-post
    // =========================================================================

    public function test_publishing_an_announcement_with_a_pdf_carries_it_to_chat(): void
    {
        $tenant = $this->makeTenant('CrossPost Media Tenant');
        $this->seedTenant($tenant);
        $author = $this->makeUser($tenant);

        $tenant->run(function () use ($author): void {
            $announcement = $this->buildAnnouncement($author->id);
            app(AnnouncementService::class)->attachFile($announcement, $this->fakePdfFile('adjunto.pdf'));

            app(AnnouncementService::class)->publish($announcement->fresh());

            $channel = $this->officialChannel();
            $message = ChatMessage::where('chat_channel_id', $channel->id)->firstOrFail();

            $copiedMedia = $message->getMedia('attachments');
            $this->assertCount(1, $copiedMedia, 'The cross-post message must carry exactly one copied attachment');
            $this->assertSame(
                ChatMessage::class,
                $copiedMedia->first()->model_type,
                'The copied media owner must be the ChatMessage, not the Announcement (design ADR-3)'
            );
            $this->assertSame($message->id, $copiedMedia->first()->model_id);

            // The original announcement attachment is untouched (copy, not move).
            $this->assertSame(1, $announcement->fresh()->getMedia('attachments')->count());
        });
    }

    public function test_text_only_announcement_cross_posts_without_media(): void
    {
        $tenant = $this->makeTenant('CrossPost NoMedia Tenant');
        $this->seedTenant($tenant);
        $author = $this->makeUser($tenant);

        $tenant->run(function () use ($author): void {
            $announcement = $this->buildAnnouncement($author->id);

            app(AnnouncementService::class)->publish($announcement);

            $channel = $this->officialChannel();
            $message = ChatMessage::where('chat_channel_id', $channel->id)->firstOrFail();

            $this->assertCount(0, $message->getMedia('attachments'), 'A text-only announcement must cross-post with zero media, no error');
        });
    }

    public function test_attachment_copy_failure_does_not_break_publish(): void
    {
        $tenant = $this->makeTenant('CrossPost CopyFail Tenant');
        $this->seedTenant($tenant);
        $author = $this->makeUser($tenant);

        $tenant->run(function () use ($author): void {
            $announcement = $this->buildAnnouncement($author->id);
            $media = app(AnnouncementService::class)->attachFile($announcement, $this->fakePdfFile('adjunto.pdf'));

            // Corrupt the underlying stored file so Media::copy() throws when the
            // cross-post tries to duplicate it — simulates a storage failure.
            Storage::disk($media->disk)->delete($media->getPathRelativeToRoot());

            $published = app(AnnouncementService::class)->publish($announcement->fresh());

            $this->assertSame(
                AnnouncementStatus::PUBLISHED,
                $published->announcement_status_id,
                'Publish must succeed even when the attachment copy fails'
            );

            $channel = $this->officialChannel();
            $this->assertSame(
                1,
                ChatMessage::where('chat_channel_id', $channel->id)->count(),
                'The cross-post text message must still be created despite the copy failure'
            );
        });
    }

    public function test_republishing_does_not_duplicate_the_copied_attachment(): void
    {
        $tenant = $this->makeTenant('CrossPost RePublish Media Tenant');
        $this->seedTenant($tenant);
        $author = $this->makeUser($tenant);

        $tenant->run(function () use ($author): void {
            $announcement = $this->buildAnnouncement($author->id);
            app(AnnouncementService::class)->attachFile($announcement, $this->fakePdfFile('adjunto.pdf'));

            $service = app(AnnouncementService::class);
            $service->publish($announcement->fresh());
            $service->publish($announcement->fresh());

            $channel = $this->officialChannel();
            $messages = ChatMessage::where('chat_channel_id', $channel->id)->get();

            $this->assertCount(1, $messages, 'Re-publishing must not duplicate the cross-post message');
            $this->assertCount(1, $messages->first()->getMedia('attachments'), 'Re-publishing must not duplicate the copied attachment');
        });
    }
}
