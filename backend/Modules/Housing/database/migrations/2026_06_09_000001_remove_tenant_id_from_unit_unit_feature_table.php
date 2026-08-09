<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sec-tenant-isolation-03 — unit_unit_feature is a pivot reached ONLY through
 * its tenant-scoped parent (unit). It had tenant_id NOT NULL, but attach()/sync()
 * insert directly (no model `creating` event), so BelongsToTenant could never
 * stamp the column → guaranteed 23502 on first feature assignment.
 *
 * Following the project convention for pivots (taggable / campaign_contact /
 * media — stancl-tenancy §5): drop tenant_id entirely. Tenant isolation is inherited
 * through the cascading FK to unit, which IS tenant-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_unit_feature', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'unit_id', 'unit_feature_id']);
            $table->dropConstrainedForeignId('tenant_id');
            $table->unique(['unit_id', 'unit_feature_id']);
        });
    }

    public function down(): void
    {
        Schema::table('unit_unit_feature', function (Blueprint $table) {
            $table->dropUnique(['unit_id', 'unit_feature_id']);
            // Nullable on rollback: existing rows cannot recover a tenant value.
            $table->foreignUuid('tenant_id')->nullable()->references('id')->on('tenant')->onDelete('cascade');
            $table->index('tenant_id');
            $table->unique(['tenant_id', 'unit_id', 'unit_feature_id']);
        });
    }
};
