<?php

namespace Modules\DataImport\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * ImportRun — tracks a single payment-import execution for a tenant.
 *
 * Status lifecycle: pending → processing → ready | completed_with_errors | failed.
 * Phase lifecycle:  reading_file → processing → finalizing → done.
 *
 * The coordinator (RunImportJob) parses the workbook and dispatches a Bus::batch
 * of ProcessImportChunkJob jobs (concurrency 1) + a FinalizeImportJob via ->finally.
 * Each chunk job persists progress + an additive partial report under a row lock;
 * the finalizer computes the terminal status and runs the aggregate recompute once.
 */
#[ObservedBy([GlobalObserver::class])]
class ImportRun extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_READY,
        self::STATUS_COMPLETED_WITH_ERRORS,
        self::STATUS_FAILED,
    ];

    /**
     * Terminal statuses — a run that has reached any of these has a final outcome and
     * must NOT be re-opened or downgraded by a retried/duplicate/late finalizer, nor by
     * a job's failed() hook firing after the work already settled.
     */
    public const TERMINAL_STATUSES = [
        self::STATUS_READY,
        self::STATUS_COMPLETED_WITH_ERRORS,
        self::STATUS_FAILED,
    ];

    public const PHASE_READING_FILE = 'reading_file';

    public const PHASE_PROCESSING = 'processing';

    public const PHASE_FINALIZING = 'finalizing';

    public const PHASE_DONE = 'done';

    public const PHASES = [
        self::PHASE_READING_FILE,
        self::PHASE_PROCESSING,
        self::PHASE_FINALIZING,
        self::PHASE_DONE,
    ];

    protected $table = 'import_run';

    protected $fillable = [
        'status',
        'phase',
        'file_path',
        'progress',
        'report',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'progress' => 'array',
            'report' => 'array',
        ];
    }

    /**
     * Canonical empty report shape so additive chunk merges never hit undefined keys.
     *
     * The `bank_deltas` and `affected_unit_ids` keys are INTERIM accumulators that the
     * chunk jobs fill and the finalizer consumes + strips from the final stored report.
     *
     * @return array{
     *   total: int,
     *   units_created: int,
     *   residents_created: int,
     *   payments_registered: int,
     *   payments_skipped: int,
     *   failed_count: int,
     *   succeeded_count: int,
     *   bank_balance_delta: list<array{bank_account: string, delta: int}>,
     *   rows: list<array{row: int, severity: string, column: string, message: string}>,
     *   bank_deltas: array<string, int>,
     *   affected_unit_ids: list<string>
     * }
     */
    public static function emptyReport(): array
    {
        return [
            'total' => 0,
            'units_created' => 0,
            'residents_created' => 0,
            'payments_registered' => 0,
            'payments_skipped' => 0,
            'failed_count' => 0,
            'succeeded_count' => 0,
            'bank_balance_delta' => [],
            'rows' => [],
            // interim accumulators — stripped by FinalizeImportJob:
            'bank_deltas' => [],
            'affected_unit_ids' => [],
        ];
    }
}
