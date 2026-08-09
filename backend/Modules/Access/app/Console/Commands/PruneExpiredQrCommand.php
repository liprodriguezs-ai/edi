<?php

namespace Modules\Access\Console\Commands;

use Illuminate\Console\Command;
use Modules\Access\Services\AccessService;
use Modules\Tenant\Models\Tenant;

/**
 * PruneExpiredQrCommand — daily cron (§18.6).
 *
 * For each tenant, soft-deletes visitors whose QR expired more than the configured
 * window (default 30 days) ago AND that never produced an access_log entry. The
 * immutable access_log is never pruned: visitors that ever entered keep their record.
 */
class PruneExpiredQrCommand extends Command
{
    /** Days after expiry before an UNUSED visitor QR is pruned. */
    private const PRUNE_AFTER_DAYS = 30;

    protected $signature = 'access:prune-expired-qr';

    protected $description = 'Daily: soft-delete visitors whose QR expired > 30 days ago and never produced an access_log, per tenant.';

    public function handle(AccessService $accessService): int
    {
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            $tenant->run(function () use ($accessService, $tenant): void {
                $count = $accessService->pruneExpiredQr(self::PRUNE_AFTER_DAYS);
                $label = $tenant->name ?? $tenant->id;
                $this->info("[{$label}] {$count} expired QR visitor(s) pruned.");
            });
        }

        $this->info('access:prune-expired-qr completed.');

        return self::SUCCESS;
    }
}
