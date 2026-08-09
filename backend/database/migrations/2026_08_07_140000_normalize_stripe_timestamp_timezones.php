<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-shot correction for rows written before App\Casts\AppTimezoneCast
 * existed (stripe-native-subscription-controls, Phase 9).
 *
 * Carbon 3 changed `Carbon::createFromTimestamp()` to return UTC (Carbon 2
 * returned the app timezone). Cashier persists Stripe's Unix timestamps
 * through that call into `timestamp without time zone` columns, so every row
 * written before the cast landed carries the UTC/app-timezone offset as a
 * skew (e.g. +6h for America/Mexico_City) relative to Eloquent-written
 * columns on the same row (see `subscriptions.created_at`).
 *
 * This is a ONE-SHOT correction — nothing is in production, so no
 * backward-compatibility path is required. The migrations ledger IS the
 * idempotency mechanism: this is not a re-runnable command, and it does not
 * attempt to detect "was this row already corrected" via a value heuristic.
 */
return new class extends Migration
{
    /**
     * @var list<array{table: string, column: string}>
     */
    private const AFFECTED_COLUMNS = [
        ['table' => 'subscriptions', 'column' => 'trial_ends_at'],
        ['table' => 'subscriptions', 'column' => 'ends_at'],
        ['table' => 'user', 'column' => 'trial_ends_at'],
    ];

    public function up(): void
    {
        foreach (self::AFFECTED_COLUMNS as ['table' => $table, 'column' => $column]) {
            DB::statement(
                sprintf(
                    'UPDATE "%s" SET "%s" = ("%s" AT TIME ZONE \'UTC\') AT TIME ZONE ? WHERE "%s" IS NOT NULL',
                    $table,
                    $column,
                    $column,
                    $column,
                ),
                [config('app.timezone')],
            );
        }
    }

    public function down(): void
    {
        foreach (self::AFFECTED_COLUMNS as ['table' => $table, 'column' => $column]) {
            DB::statement(
                sprintf(
                    'UPDATE "%s" SET "%s" = ("%s" AT TIME ZONE ?) AT TIME ZONE \'UTC\' WHERE "%s" IS NOT NULL',
                    $table,
                    $column,
                    $column,
                    $column,
                ),
                [config('app.timezone')],
            );
        }
    }
};
