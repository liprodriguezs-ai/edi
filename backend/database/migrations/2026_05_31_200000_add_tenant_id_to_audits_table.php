<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a nullable, indexed `tenant_id` to the `audits` table so audit-log
 * reports can be scoped per tenant (single-database tenancy). The column is
 * stamped on write by QCH\Core\Models\Audit::save() from the current tenant.
 *
 * Existing audit rows (created before this column) keep tenant_id = null and
 * therefore never appear in any tenant's audit-log report — acceptable.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = config('audit.drivers.database.connection', config('database.default'));
        $table = config('audit.drivers.database.table', 'audits');

        Schema::connection($connection)->table($table, function (Blueprint $table): void {
            $table->uuid('tenant_id')->nullable()->after('id')->comment('FK to tenant for scoping audit logs per organization (null for pre-tenancy rows)');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        $connection = config('audit.drivers.database.connection', config('database.default'));
        $table = config('audit.drivers.database.table', 'audits');

        Schema::connection($connection)->table($table, function (Blueprint $table): void {
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
