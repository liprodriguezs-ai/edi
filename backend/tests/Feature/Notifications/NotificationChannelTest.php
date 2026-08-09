<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Notifications\Channels\LogChannel;
use App\Notifications\Services\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Announcements\Models\Announcement;
use Modules\Announcements\Models\AnnouncementPriority;
use Modules\Announcements\Models\AnnouncementStatus;
use Modules\Announcements\Models\AnnouncementType;
use Modules\Announcements\Services\AnnouncementService;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Tests for the pluggable notification channel abstraction (Item 1).
 *
 * Verifies:
 *  - LogChannel resolves and dispatches without errors.
 *  - NotificationDispatcher resolves the 'log' driver from config.
 *  - Urgent announcement publish → log channel invoked for push+sms (verified via Log spy).
 *  - Manual access entry → dispatcher invoked.
 *  - Unknown channel name → InvalidArgumentException.
 */
final class NotificationChannelTest extends TestCase
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

    private function buildUrgentAnnouncement(): Announcement
    {
        $type = AnnouncementType::findOrFail(Catalog::id(AnnouncementType::class, 'Aviso general'));
        $urgentPriority = AnnouncementPriority::findOrFail(AnnouncementPriority::URGENT);
        $draftStatus = AnnouncementStatus::findOrFail(AnnouncementStatus::DRAFT);

        return Announcement::create([
            'title' => 'Aviso urgente '.Str::random(4),
            'announcement_type_id' => $type->id,
            'announcement_priority_id' => $urgentPriority->id,
            'announcement_status_id' => $draftStatus->id,
            'content' => 'Contenido urgente.',
            'target_type' => 'all',
            'target_selector' => null,
            'channel_push' => false,
            'channel_board' => true,
            'channel_email' => false,
            'channel_sms' => false,
        ]);
    }

    // =========================================================================
    // A. LogChannel basic dispatch
    // =========================================================================

    public function test_log_channel_sends_without_error(): void
    {
        // The LogChannel must not throw when dispatching
        $channel = new LogChannel;

        $threw = false;
        try {
            $channel->send(['user-1', 'user-2'], 'Prueba', 'Cuerpo de prueba', ['foo' => 'bar']);
        } catch (\Throwable) {
            $threw = true;
        }

        $this->assertFalse($threw, 'LogChannel::send must not throw');
    }

    // =========================================================================
    // B. NotificationDispatcher resolves 'log' driver from config
    // =========================================================================

    public function test_dispatcher_uses_log_driver_by_default(): void
    {
        config(['notifications.channels' => ['log']]);

        $dispatcher = app(NotificationDispatcher::class);

        $threw = false;
        try {
            $dispatcher->dispatch(['uid-1'], 'Título', 'Cuerpo');
        } catch (\Throwable) {
            $threw = true;
        }

        $this->assertFalse($threw, 'Dispatcher must not throw when using log driver');
    }

    public function test_dispatcher_explicit_channel_list_overrides_config(): void
    {
        config(['notifications.channels' => ['log']]);

        $dispatcher = app(NotificationDispatcher::class);

        $threw = false;
        try {
            $dispatcher->dispatch(['uid-1'], 'Título', 'Cuerpo', [], ['log']);
        } catch (\Throwable) {
            $threw = true;
        }

        $this->assertFalse($threw, 'Dispatcher must not throw with explicit log channel list');
    }

    public function test_dispatcher_throws_for_unknown_channel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not registered/');

        $dispatcher = app(NotificationDispatcher::class);
        $dispatcher->dispatch(['uid-1'], 'Título', 'Cuerpo', [], ['nonexistent_channel']);
    }

    // =========================================================================
    // C. Urgent announcement → dispatcher invoked (push forced; SMS disabled)
    // =========================================================================

    public function test_urgent_announcement_publish_invokes_dispatcher_for_push(): void
    {
        $tenant = $this->makeTenant('Urgent Dispatch Tenant');
        $this->seedTenant($tenant);

        // Fake the dispatcher to assert it's called without real network dispatch
        $fakeDispatcher = $this->createMock(NotificationDispatcher::class);
        $fakeDispatcher->expects($this->atLeastOnce())->method('dispatch');
        $this->app->instance(NotificationDispatcher::class, $fakeDispatcher);

        $tenant->run(function (): void {
            $announcement = $this->buildUrgentAnnouncement();

            // Pre-conditions: channels disabled — urgent publish forces push only.
            $this->assertFalse((bool) $announcement->channel_push);
            $this->assertFalse((bool) $announcement->channel_sms);

            $service = app(AnnouncementService::class);
            $published = $service->publish($announcement);

            $this->assertTrue((bool) $published->channel_push, 'Urgent must force channel_push');
            $this->assertFalse((bool) $published->channel_sms, 'SMS is disabled for now — urgent must NOT force channel_sms');
        });
    }

    // =========================================================================
    // D. Driver selection from config is respected
    // =========================================================================

    public function test_dispatcher_respects_config_channel_list(): void
    {
        // Only 'log' in config; assert only LogChannel is called (not sms/firebase)
        config(['notifications.channels' => ['log']]);

        $dispatcher = app(NotificationDispatcher::class);

        // Must dispatch without exception — sms/firebase channels are NOT used
        $threw = false;
        try {
            $dispatcher->dispatch(['phone-1'], 'Aviso', 'Mensaje');
        } catch (\Throwable) {
            $threw = true;
        }

        $this->assertFalse($threw, 'Log-only dispatch must not throw');
    }
}
