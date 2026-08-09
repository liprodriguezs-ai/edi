<?php

namespace Modules\Announcements\Console\Commands;

use Illuminate\Console\Command;
use Modules\Announcements\Models\Announcement;
use Modules\Announcements\Models\AnnouncementStatus;
use Modules\Announcements\Services\AnnouncementService;
use Modules\Tenant\Models\Tenant;

/**
 * announcements:process-scheduled — module cron (§12.3.3 / §12.6).
 *
 * Per tenant:
 *  1. Activates scheduled announcements: DRAFT rows whose publish_at is due are
 *     published NOW — the audience is notified AT ACTIVATION, never before
 *     ("Aviso programado activado → Push").
 *  2. Archives published announcements past their expires_at (the admin list
 *     shows them as "Archivado"; the board already hides them).
 *
 * Idempotent: once published/archived the rows no longer match their query,
 * so a re-run never re-notifies.
 */
class ProcessScheduledAnnouncementsCommand extends Command
{
    protected $signature = 'announcements:process-scheduled';

    protected $description = 'Activate due scheduled announcements (notifying on activation) and archive expired ones, per tenant.';

    public function handle(AnnouncementService $service): int
    {
        foreach (Tenant::all() as $tenant) {
            $tenant->run(function () use ($service, $tenant): void {
                $label = $tenant->name ?? $tenant->id;

                $due = Announcement::where('announcement_status_id', AnnouncementStatus::DRAFT)
                    ->whereNotNull('publish_at')
                    ->where('publish_at', '<=', now())
                    ->get();

                foreach ($due as $announcement) {
                    $service->publish($announcement);
                }

                $expired = Announcement::where('announcement_status_id', AnnouncementStatus::PUBLISHED)
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<', now()->toDateString())
                    ->get();

                foreach ($expired as $announcement) {
                    $service->archive($announcement);
                }

                if ($due->isNotEmpty() || $expired->isNotEmpty()) {
                    $this->info("[{$label}] activated {$due->count()} scheduled, archived {$expired->count()} expired.");
                }
            });
        }

        $this->info('announcements:process-scheduled completed.');

        return self::SUCCESS;
    }
}
