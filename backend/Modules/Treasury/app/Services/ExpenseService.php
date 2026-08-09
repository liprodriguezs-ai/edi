<?php

namespace Modules\Treasury\Services;

use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\NotificationCategory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\Expense;
use Modules\Treasury\Models\ExpenseStatus;
use QCH\Core\Support\TenantMoney;

/**
 * Business rules for expenses.
 *
 * Responsibilities:
 * - Register an expense (status=paid) and decrement bank_account.current_balance.
 * - Void an expense (status=voided) and restore bank_account.current_balance.
 * - Duplicate detection: warn when same provider + period + similar amount within 7 days.
 * - Edit guard: same-day editing for treasurer; anytime for president/admin (delegated to policy; service flags it).
 *
 * MONEY ARITHMETIC:
 *   To bypass MoneyIntegerCast for arithmetic updates, we use the same read-modify-write
 *   pattern as BankAccountService: lockForUpdate, getRawOriginal, integer arithmetic, save().
 *   The DB::raw expression pattern is also valid but lockForUpdate + raw int is used here
 *   for consistency with the existing Treasury pattern.
 *
 * DUPLICATE DETECTION:
 *   Decision: soft warning returned as a structured result.
 *   If `force` is false and a duplicate is detected, return ['duplicate' => true, 'expense' => null].
 *   If `force` is true (or no duplicate), proceed and return ['duplicate' => false, 'expense' => Expense].
 *   The caller (controller override) reads the duplicate flag and either returns 409 or 201.
 *
 * EDIT GUARD (§9.6 r1 + §9.2):
 *   Only COSMETIC fields are editable after registration (concept/description/notes,
 *   category and beneficiary). Money, account, status and period fields are immutable —
 *   to change them, void the expense and register it again (void + recreate). The
 *   treasurer's same-day window (§9.2 ⚠️) is enforced in ExpenseController::performUpdate.
 *
 * COMMUNITY_PROJECT_ID:
 *   Stored as a nullable UUID with NO FK constraint. The Projects module (Module 7) will add
 *   the FK constraint via: ALTER TABLE expense ADD CONSTRAINT fk_expense_project FOREIGN KEY
 *   (community_project_id) REFERENCES community_project(id) ON DELETE SET NULL.
 */
class ExpenseService
{
    use NotifiesResidents;

    /** Threshold (minor units) above which an expense notifies the President (§9.8). */
    private const LARGE_EXPENSE_THRESHOLD = 500000; // $5,000.00 MXN

    /** Threshold (minor units) below which a low-balance alert fires (§9.8). */
    private const LOW_BALANCE_THRESHOLD = 1000000; // $10,000.00 MXN

    /**
     * Cosmetic fields editable after registration (§9.6 r1). Money, account,
     * status and period fields are immutable: void + recreate instead.
     *
     * @var array<int, string>
     */
    public const UPDATABLE_ATTRIBUTES = [
        'concept',
        'description',
        'notes',
        'expense_category_id',
        'beneficiary_type_id',
        'employee_id',
        'provider_id',
        'beneficiary_text',
    ];

    /**
     * Format a minor-units integer amount as a MXN currency string for notification copy.
     */
    private function formatMoney(int $amountRaw): string
    {
        return TenantMoney::format($amountRaw);
    }

    /**
     * Register an expense with duplicate detection.
     *
     * @param  array<string, mixed>  $data  Validated expense data (amount in minor units int).
     * @param  bool  $force  Skip duplicate detection and force register.
     * @return array{duplicate: bool, expense: ?Expense}
     */
    public function register(array $data, bool $force = false): array
    {
        $newBalanceRaw = null;

        $result = DB::transaction(function () use ($data, $force, &$newBalanceRaw): array {
            // Duplicate detection (§9.6 rule 6): same provider + period + similar amount within 7 days.
            if (! $force && isset($data['provider_id'])) {
                $duplicate = $this->detectDuplicate($data);
                if ($duplicate) {
                    return ['duplicate' => true, 'expense' => null];
                }
            }

            $statusId = ExpenseStatus::PAID;

            $expense = Expense::create(array_merge($data, [
                'expense_status_id' => $statusId,
            ]));

            // Decrement bank account balance.
            $amountRaw = (int) $data['amount'];
            $this->adjustBankBalance($data['bank_account_id'], -$amountRaw);

            $account = BankAccount::find($data['bank_account_id']);
            $newBalanceRaw = $account ? (int) $account->getRawOriginal('current_balance') : null;

            return ['duplicate' => false, 'expense' => $expense->load('status', 'bank_account', 'category', 'beneficiaryType')];
        });

        // §9.8: notify AFTER commit so a notification error never rolls back the expense.
        if (! $result['duplicate'] && $result['expense'] !== null) {
            $this->notifyExpenseRegistered($result['expense'], $newBalanceRaw);
        }

        return $result;
    }

    /**
     * Dispatch the §9.8 expense notifications.
     *
     * Per §9.8 / §17.4: large expense (> $5,000) is Push to the President
     * (channels ['log'], firebase_push appended by config); low balance is
     * Push + email to Tesorero, Presidente and Administrador. Both carry
     * data['category'] = REPORTS so per-category preferences apply.
     */
    private function notifyExpenseRegistered(Expense $expense, ?int $newBalanceRaw): void
    {
        $amountRaw = (int) $expense->getRawOriginal('amount');

        if ($amountRaw > self::LARGE_EXPENSE_THRESHOLD) {
            $this->notifyUsers(
                $this->userIdsWithRole('president'),
                'Egreso elevado registrado',
                'Se registró un egreso de '.$this->formatMoney($amountRaw).': '.($expense->concept ?? $expense->description ?? 'sin concepto').'.',
                [
                    'click_action' => '/expense',
                    'expense_id' => $expense->id,
                    'category' => NotificationCategory::REPORTS,
                ],
            );
        }

        if ($newBalanceRaw !== null && $newBalanceRaw < self::LOW_BALANCE_THRESHOLD) {
            $this->notifyUsers(
                $this->userIdsWithRole(['treasurer', 'president', 'administrator']),
                'Saldo bancario bajo',
                'El saldo de la cuenta bajó a '.$this->formatMoney($newBalanceRaw).'. Revisa las finanzas del condominio.',
                [
                    'click_action' => '/bank-account',
                    'bank_account_id' => $expense->bank_account_id,
                    'category' => NotificationCategory::REPORTS,
                ],
                channels: ['log', 'resend_email']
            );
        }
    }

    /**
     * Update the cosmetic fields of an expense (§9.6 r1).
     *
     * Money/account/status/period fields are NEVER updated here — changing
     * amount or bank account requires void + recreate so current_balance can
     * never drift. Voided expenses are read-only.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException When the expense is already voided.
     */
    public function update(Expense $expense, array $attributes): Expense
    {
        return DB::transaction(function () use ($expense, $attributes): Expense {
            $locked = Expense::where('id', $expense->id)->lockForUpdate()->firstOrFail();

            if ($locked->expense_status_id === ExpenseStatus::VOIDED) {
                throw ValidationException::withMessages([
                    'expense' => 'Un egreso anulado no se puede editar.',
                ]);
            }

            $locked->fill(Arr::only($attributes, self::UPDATABLE_ATTRIBUTES));
            $locked->save();

            return $locked;
        });
    }

    /**
     * Void an expense: set status=voided, record reason, restore bank balance.
     * Expenses are NEVER deleted — only voided (§9.6 rule 1).
     *
     * The status is re-checked on a lockForUpdate re-fetch inside the transaction
     * so two concurrent voids can never restore the balance twice (§18.4).
     *
     * @throws ValidationException When the expense is already voided.
     */
    public function void(Expense $expense, string $reason): Expense
    {
        return DB::transaction(function () use ($expense, $reason): Expense {
            $voidedStatus = ExpenseStatus::findOrFail(ExpenseStatus::VOIDED);

            $locked = Expense::where('id', $expense->id)->lockForUpdate()->firstOrFail();

            if ($locked->expense_status_id === $voidedStatus->id) {
                throw ValidationException::withMessages([
                    'expense' => 'El egreso ya está anulado.',
                ]);
            }

            $locked->expense_status_id = $voidedStatus->id;
            $locked->void_reason = $reason;
            $locked->save();

            // Restore bank account balance (expense reversed).
            $amountRaw = (int) $locked->getRawOriginal('amount');
            $this->adjustBankBalance($locked->bank_account_id, $amountRaw);

            return $locked->refresh()->load('status', 'bank_account');
        });
    }

    /**
     * Detect a potential duplicate expense.
     *
     * Fires when: same provider_id + overlapping period (year + month match when set) +
     * similar amount (within 10% tolerance) + registered within the last 7 days.
     */
    private function detectDuplicate(array $data): bool
    {
        $amountRaw = (int) $data['amount'];
        $tolerance = (int) round($amountRaw * 0.10);
        $minAmount = $amountRaw - $tolerance;
        $maxAmount = $amountRaw + $tolerance;

        $query = Expense::query()
            ->where('provider_id', $data['provider_id'])
            ->where('year', $data['year'])
            ->where('expense_status_id', '!=', ExpenseStatus::VOIDED)
            ->whereBetween('amount', [$minAmount, $maxAmount])
            ->where('created_at', '>=', now()->subDays(7));

        if (! empty($data['month'])) {
            $query->where('month', $data['month']);
        }

        return $query->exists();
    }

    /**
     * Adjust a bank account's current_balance by a signed delta (minor units).
     * Uses lockForUpdate + raw integer arithmetic to bypass MoneyIntegerCast,
     * consistent with PaymentService and BankAccountService patterns.
     *
     * The lookup runs under TenantScope: an account belonging to another tenant
     * (or missing) raises ModelNotFoundException — an expense must NEVER persist
     * without moving a visible account of the current tenant (§9.6 r2).
     *
     * @throws ModelNotFoundException
     */
    private function adjustBankBalance(string $bankAccountId, int $deltaRaw): void
    {
        $account = BankAccount::where('id', $bankAccountId)->lockForUpdate()->firstOrFail();

        $current = (int) $account->getRawOriginal('current_balance');
        $account->current_balance = $current + $deltaRaw;
        $account->save();
    }
}
