<?php

declare(strict_types=1);

namespace Tests\Feature\Announcements;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Announcements\Models\Announcement;
use Modules\Announcements\Models\AnnouncementPriority;
use Modules\Announcements\Models\AnnouncementRead;
use Modules\Announcements\Models\AnnouncementStatus;
use Modules\Announcements\Models\AnnouncementType;
use Modules\Announcements\Services\AnnouncementService;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Integration tests for Module 8 — Comunicación/Avisos (Announcements).
 *
 * Flows covered:
 *  A. Catalogs seeded per tenant (type, priority, status)
 *  B. Publish draft → published; urgent forces push channel (SMS disabled for now)
 *  C. Archive keeps row (status=archived, not deleted)
 *  D. Read tracking: idempotent per user
 *  E. Board returns only published non-expired
 *  F. Tenant isolation on all tables
 */
final class AnnouncementsModuleTest extends TestCase
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

    private function buildAnnouncement(array $overrides = []): Announcement
    {
        $type = AnnouncementType::findOrFail(Catalog::id(AnnouncementType::class, 'Aviso general'));
        $priority = AnnouncementPriority::findOrFail(AnnouncementPriority::NORMAL);
        $draftStatus = AnnouncementStatus::findOrFail(AnnouncementStatus::DRAFT);

        return Announcement::create(array_merge([
            'title' => 'Aviso de prueba '.Str::random(6),
            'announcement_type_id' => $type->id,
            'announcement_priority_id' => $priority->id,
            'announcement_status_id' => $draftStatus->id,
            'content' => 'Contenido del aviso de prueba.',
            'target_type' => 'all',
            'target_selector' => null,
            'channel_push' => true,
            'channel_board' => true,
            'channel_email' => false,
            'channel_sms' => false,
        ], $overrides));
    }

    // =========================================================================
    // A. Catalog seeding per tenant
    // =========================================================================

    public function test_catalogs_seeded_per_tenant(): void
    {
        $tenant = $this->makeTenant('Catalog Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $this->assertSame(6, AnnouncementType::count(), 'Must have 6 announcement types');
            $this->assertSame(3, AnnouncementPriority::count(), 'Must have 3 announcement priorities');
            $this->assertSame(3, AnnouncementStatus::count(), 'Must have 3 announcement statuses');

            // Verify specific keys
            $this->assertNotNull(AnnouncementType::find(Catalog::id(AnnouncementType::class, 'Aviso general')));
            $this->assertNotNull(AnnouncementType::find(Catalog::id(AnnouncementType::class, 'Asamblea')));
            $this->assertNotNull(AnnouncementType::find(Catalog::id(AnnouncementType::class, 'Mantenimiento')));
            $this->assertNotNull(AnnouncementType::find(Catalog::id(AnnouncementType::class, 'Financiero')));
            $this->assertNotNull(AnnouncementType::find(Catalog::id(AnnouncementType::class, 'Seguridad')));
            $this->assertNotNull(AnnouncementType::find(Catalog::id(AnnouncementType::class, 'Social / Evento')));

            $this->assertNotNull(AnnouncementPriority::find(AnnouncementPriority::NORMAL));
            $this->assertNotNull(AnnouncementPriority::find(AnnouncementPriority::IMPORTANT));
            $this->assertNotNull(AnnouncementPriority::find(AnnouncementPriority::URGENT));

            $this->assertNotNull(AnnouncementStatus::find(AnnouncementStatus::DRAFT));
            $this->assertNotNull(AnnouncementStatus::find(AnnouncementStatus::PUBLISHED));
            $this->assertNotNull(AnnouncementStatus::find(AnnouncementStatus::ARCHIVED));
        });
    }

    // =========================================================================
    // B. Publish draft → published; urgent forces channels
    // =========================================================================

    public function test_publish_changes_status_to_published(): void
    {
        $tenant = $this->makeTenant('Publish Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $announcement = $this->buildAnnouncement();

            $service = app(AnnouncementService::class);
            $published = $service->publish($announcement);

            $published->refresh();
            $this->assertSame(AnnouncementStatus::PUBLISHED, $published->announcement_status_id, 'Status must be published after publish()');
            $this->assertNotNull($published->publish_at, 'publish_at must be set after publish()');
        });
    }

    public function test_urgent_priority_forces_push_channel_only(): void
    {
        $tenant = $this->makeTenant('Urgent Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $urgentPriority = AnnouncementPriority::findOrFail(AnnouncementPriority::URGENT);

            $announcement = $this->buildAnnouncement([
                'announcement_priority_id' => $urgentPriority->id,
                'channel_push' => false,   // explicitly disabled — should be forced to true
                'channel_sms' => false,    // SMS disabled for now — must NOT be forced
            ]);

            $this->assertFalse((bool) $announcement->channel_push, 'Pre-condition: push disabled');
            $this->assertFalse((bool) $announcement->channel_sms, 'Pre-condition: sms disabled');

            $service = app(AnnouncementService::class);
            $published = $service->publish($announcement);

            $this->assertTrue((bool) $published->channel_push, 'Urgent: channel_push must be forced true');
            $this->assertFalse((bool) $published->channel_sms, 'Urgent: channel_sms must NOT be forced (SMS disabled for now)');
        });
    }

    public function test_non_urgent_does_not_force_channels(): void
    {
        $tenant = $this->makeTenant('Normal Channel Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $normalPriority = AnnouncementPriority::findOrFail(AnnouncementPriority::NORMAL);

            $announcement = $this->buildAnnouncement([
                'announcement_priority_id' => $normalPriority->id,
                'channel_push' => false,
                'channel_sms' => false,
            ]);

            $service = app(AnnouncementService::class);
            $published = $service->publish($announcement);

            $this->assertFalse((bool) $published->channel_push, 'Normal priority must NOT force channel_push');
            $this->assertFalse((bool) $published->channel_sms, 'Normal priority must NOT force channel_sms');
        });
    }

    // =========================================================================
    // C. Archive keeps row (status=archived, never deleted)
    // =========================================================================

    public function test_archive_sets_status_archived_and_does_not_delete(): void
    {
        $tenant = $this->makeTenant('Archive Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $announcement = $this->buildAnnouncement();
            $announcementId = $announcement->id;

            $service = app(AnnouncementService::class);
            $service->publish($announcement);
            $service->archive($announcement);

            // Row must still exist
            $found = Announcement::withTrashed()->find($announcementId);
            $this->assertNotNull($found, 'Archived announcement must still exist in DB');
            $this->assertNull($found->deleted_at, 'Archived announcement must NOT be soft-deleted');

            $this->assertSame(AnnouncementStatus::ARCHIVED, $found->announcement_status_id, 'Status must be archived');
        });
    }

    // =========================================================================
    // D. Read tracking: idempotent per user
    // =========================================================================

    public function test_mark_read_creates_read_record(): void
    {
        $tenant = $this->makeTenant('Read Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $announcement = $this->buildAnnouncement();
            $userId = (string) Str::uuid();

            $service = app(AnnouncementService::class);
            $readRecord = $service->markRead($announcement, $userId);

            $this->assertNotNull($readRecord->id, 'Read record must be created');
            $this->assertSame($announcement->id, $readRecord->announcement_id);
            $this->assertSame($userId, $readRecord->user_id);
            $this->assertNotNull($readRecord->read_at);

            $this->assertSame(1, AnnouncementRead::where('announcement_id', $announcement->id)->count());
        });
    }

    public function test_mark_read_is_idempotent(): void
    {
        $tenant = $this->makeTenant('Idempotent Read Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $announcement = $this->buildAnnouncement();
            $userId = (string) Str::uuid();

            $service = app(AnnouncementService::class);
            $service->markRead($announcement, $userId);
            $service->markRead($announcement, $userId); // second call must be no-op
            $service->markRead($announcement, $userId); // third call must be no-op

            $this->assertSame(1, AnnouncementRead::where('announcement_id', $announcement->id)
                ->where('user_id', $userId)->count(), 'Read tracking must be idempotent — only 1 row per user');
        });
    }

    public function test_multiple_users_can_each_read_announcement(): void
    {
        $tenant = $this->makeTenant('Multi User Read Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $announcement = $this->buildAnnouncement();
            $user1 = (string) Str::uuid();
            $user2 = (string) Str::uuid();
            $user3 = (string) Str::uuid();

            $service = app(AnnouncementService::class);
            $service->markRead($announcement, $user1);
            $service->markRead($announcement, $user2);
            $service->markRead($announcement, $user3);

            $this->assertSame(3, AnnouncementRead::where('announcement_id', $announcement->id)->count());
        });
    }

    // =========================================================================
    // E. Board returns only published non-expired
    // =========================================================================

    public function test_expired_announcements_not_returned_by_board(): void
    {
        $tenant = $this->makeTenant('Board Expiry Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $service = app(AnnouncementService::class);

            // Published, not expired
            $active = $this->buildAnnouncement(['expires_at' => now()->addDays(7)->toDateString()]);
            $service->publish($active);

            // Published, expired yesterday
            $expired = $this->buildAnnouncement(['expires_at' => now()->subDay()->toDateString()]);
            $service->publish($expired);

            // Draft — should not appear
            $this->buildAnnouncement();

            $publishedStatus = AnnouncementStatus::findOrFail(AnnouncementStatus::PUBLISHED);

            $board = Announcement::where('announcement_status_id', $publishedStatus->id)
                ->where(function ($q): void {
                    $q->whereNull('expires_at')
                        ->orWhere('expires_at', '>=', now()->toDateString());
                })
                ->get();

            $this->assertCount(1, $board, 'Board must only contain published, non-expired announcements');
            $this->assertSame($active->id, $board->first()->id);
        });
    }

    // =========================================================================
    // F. Tenant isolation
    // =========================================================================

    public function test_tenant_isolation_announcement_table(): void
    {
        $tenant1 = $this->makeTenant('Isolation T1');
        $tenant2 = $this->makeTenant('Isolation T2');
        $this->seedTenant($tenant1);
        $this->seedTenant($tenant2);

        $tenant1->run(function (): void {
            $this->buildAnnouncement(['title' => 'T1 Aviso']);
        });

        $tenant2->run(function (): void {
            $this->assertSame(0, Announcement::count(), 'Tenant 2 must not see Tenant 1 announcements');
        });
    }

    public function test_tenant_isolation_announcement_read_table(): void
    {
        $tenant1 = $this->makeTenant('Read Isolation T1');
        $tenant2 = $this->makeTenant('Read Isolation T2');
        $this->seedTenant($tenant1);
        $this->seedTenant($tenant2);

        $announcement = null;
        $tenant1->run(function () use (&$announcement): void {
            $announcement = $this->buildAnnouncement();
            $service = app(AnnouncementService::class);
            $service->markRead($announcement, (string) Str::uuid());
        });

        $tenant2->run(function (): void {
            $this->assertSame(0, AnnouncementRead::count(), 'Tenant 2 must not see Tenant 1 read records');
        });
    }

    public function test_catalogs_are_central_shared_across_tenants(): void
    {
        $tenant1 = $this->makeTenant('Catalog T1');
        $tenant2 = $this->makeTenant('Catalog T2');
        $this->seedTenant($tenant1);
        $this->seedTenant($tenant2);

        // announcement_type is a CENTRAL fixed-enum catalog (no tenant_id):
        // seeded once and shared by every tenant, NOT isolated per tenant.
        $tenant1->run(function (): void {
            $this->assertSame(6, AnnouncementType::count());
        });

        $tenant2->run(function (): void {
            // T2 sees the SAME central rows.
            $this->assertSame(6, AnnouncementType::count());
        });
    }
}
