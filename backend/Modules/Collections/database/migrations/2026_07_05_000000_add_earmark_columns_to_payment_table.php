<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Collections\Models\PaymentStatus;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payment', function (Blueprint $table) {
            $table->string('earmark_type', 100)->nullable()->after('notes')
                ->comment('Earmark registry key of the module-specific target this payment must fund on validation (e.g. project_contribution). Null = normal payment (FIFO).');
            $table->uuid('earmark_id')->nullable()->after('earmark_type')
                ->comment('UUID of the earmarked source entity (e.g. FK to project_contribution). Null = normal payment.');

            $table->index(['earmark_type', 'earmark_id']);
        });

        // Race lock: at most ONE payment awaiting validation per earmarked entity.
        // Partial unique indexes are Postgres-only; the PENDING_VALIDATION UUID is a
        // fixed central-catalog constant, safe to embed in the predicate.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                "CREATE UNIQUE INDEX payment_one_pending_per_earmark ON payment (tenant_id, earmark_type, earmark_id) WHERE payment_status_id = '%s' AND deleted_at IS NULL AND earmark_type IS NOT NULL",
                PaymentStatus::PENDING_VALIDATION,
            ));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS payment_one_pending_per_earmark');
        }

        Schema::table('payment', function (Blueprint $table) {
            $table->dropIndex(['earmark_type', 'earmark_id']);
            $table->dropColumn(['earmark_type', 'earmark_id']);
        });
    }
};
