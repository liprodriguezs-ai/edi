<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Early stimulation group at a health center, with enrollment and
     * attendance (REQ-SG-001). name is unique per tenant among non-deleted
     * rows. is_active gates enrollment/attendance writes only, never reads
     * (design D11).
     */
    public function up(): void
    {
        Schema::create('stimulation_group', function (Blueprint $table) {
            $table->comment('Early stimulation group at a health center, with enrollment and attendance');
            BaseModel::columns($table, true);
            $table->string('name')->comment('Group name; unique per tenant among non-deleted rows');
            $table->uuid('stimulation_group_type_id')->index()->comment('FK to tenant catalog stimulation_group_type');
            $table->uuid('health_center_id')->index()->comment('FK to health_center where the group meets');
            $table->uuid('promoter_user_id')->index()->comment('FK to user who leads/promotes the group; must be a tenant member (D6)');
            $table->date('start_date')->comment('Date the group starts operating');
            $table->date('end_date')->nullable()->comment('Date the group ends; must be >= start_date');
            $table->boolean('is_active')->default(true)->comment('Whether the group accepts enrollment and attendance writes (D11)');
        });

        DB::statement(
            'CREATE UNIQUE INDEX stimulation_group_tenant_name_unique ON stimulation_group (tenant_id, name) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS stimulation_group_tenant_name_unique');
        Schema::dropIfExists('stimulation_group');
    }
};
