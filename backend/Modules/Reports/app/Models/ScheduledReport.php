<?php

namespace Modules\Reports\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class ScheduledReport extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'scheduled_report';

    /** Report key constants — map to ReportService methods. */
    public const KEY_DELINQUENCY = 'delinquency';

    public const KEY_INCOME_STATEMENT = 'income_statement';

    public const KEY_ACCOUNT_BALANCES = 'account_balances';

    public const KEY_AUDIT_LOG = 'audit_log';

    public const KEY_COLLECTION_PROGRESS = 'collection_progress';

    // FINANCIAL
    public const KEY_CASH_FLOW = 'cash_flow';

    public const KEY_EXPENSES_BY_CATEGORY = 'expenses_by_category';

    public const KEY_ANNUAL_FISCAL = 'annual_fiscal';

    // COLLECTIONS
    public const KEY_UNIT_STATEMENT = 'unit_statement';

    public const KEY_GLOBAL_STATEMENT = 'global_statement';

    public const KEY_PAYMENT_HISTORY = 'payment_history';

    public const KEY_CREDIT_BALANCES = 'credit_balances';

    // OPERATIONS
    public const KEY_ACCESS_LOG_REPORT = 'access_log_report';

    public const KEY_STAFF_REPORT = 'staff_report';

    public const KEY_PROVIDER_HISTORY = 'provider_history';

    public const KEY_PROJECT_PROGRESS = 'project_progress';

    // ADMINISTRATIVE
    public const KEY_RESIDENT_DIRECTORY = 'resident_directory';

    public const KEY_ACTIVE_BOARD = 'active_board';

    public const KEY_ANNOUNCEMENTS_SENT = 'announcements_sent';

    /** Frequency constants. */
    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MONTHLY = 'monthly';

    protected $fillable = [
        'report_key',
        'config',
        'frequency',
        'format',
        'recipients',
        'next_run_at',
        'last_run_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'recipients' => 'array',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Compute the next_run_at timestamp based on frequency.
     */
    public function computeNextRun(): self
    {
        $this->next_run_at = match ($this->frequency) {
            self::FREQUENCY_DAILY => now()->addDay()->startOfDay()->setHour(8),
            self::FREQUENCY_WEEKLY => now()->next('Monday')->startOfDay()->setHour(8),
            self::FREQUENCY_MONTHLY => now()->addMonthNoOverflow()->startOfMonth()->setHour(8),
            default => null,
        };

        return $this;
    }
}
