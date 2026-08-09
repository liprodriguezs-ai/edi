<?php

namespace Modules\Reports\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\Announcements\Models\Announcement;
use Modules\Announcements\Models\AnnouncementPriority;
use Modules\Announcements\Models\AnnouncementStatus;
use Modules\Announcements\Models\AnnouncementType;
use Modules\Announcements\Services\AnnouncementService;

/**
 * ReportBoardPublisher — §15.4.2 "Publicar en pizarra (transparencia)".
 *
 * Turns a generated report PDF into a cluster-wide Announcement: creates the
 * draft, attaches the PDF through AnnouncementService::attachFile (so the 5
 * attachments / 10 MB limits apply) and publishes it via the same service so
 * the audience notification fan-out (§12.6) runs identically to any other
 * announcement.
 */
class ReportBoardPublisher
{
    public function __construct(
        private readonly AnnouncementService $announcementService,
    ) {}

    /**
     * Publish a report PDF to the cluster board (audience: the whole cluster).
     */
    public function publish(
        string $key,
        string $pdfContent,
        string $reportTitle,
        string $authorUserId,
        ?string $customTitle = null,
    ): Announcement {
        $announcement = Announcement::create([
            'title' => $customTitle ?? $reportTitle,
            'announcement_type_id' => AnnouncementType::idOfKey(AnnouncementType::KEY_FINANCIAL),
            'announcement_priority_id' => AnnouncementPriority::NORMAL,
            'announcement_status_id' => AnnouncementStatus::DRAFT,
            'content' => 'Se publicó el reporte "'.$reportTitle.'" para consulta de toda la comunidad (transparencia).',
            'target_type' => 'all',
            'target_selector' => null,
            'channel_push' => false,
            'channel_board' => true,
            'channel_email' => false,
            'channel_sms' => false,
            'author_user_id' => $authorUserId,
        ]);

        $this->attachPdf($announcement, $key, $pdfContent);

        return $this->announcementService->publish($announcement);
    }

    /**
     * Wrap the raw PDF bytes in an UploadedFile (test-mode so a non-HTTP file is
     * accepted) and route it through the announcement attachment rules.
     */
    private function attachPdf(Announcement $announcement, string $key, string $pdfContent): void
    {
        $tmpPath = (string) tempnam(sys_get_temp_dir(), 'vecinia_report_');

        try {
            file_put_contents($tmpPath, $pdfContent);

            $filename = 'reporte_'.Str::slug($key, '_').'_'.now()->format('Ymd_His').'.pdf';
            $file = new UploadedFile($tmpPath, $filename, 'application/pdf', null, true);

            $this->announcementService->attachFile($announcement, $file);
        } finally {
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }
}
