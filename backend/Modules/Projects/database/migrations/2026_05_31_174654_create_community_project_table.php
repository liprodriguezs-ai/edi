<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('community_project', function (Blueprint $table) {
            BaseModel::columns($table, true);

            $table->string('title')->comment('Public title of the community project.');
            $table->text('description')->nullable()->comment('Detailed description shown to residents.');

            $table->uuid('community_project_category_id')->comment('FK to community_project_category.');
            $table->foreign('community_project_category_id')
                ->references('id')
                ->on('community_project_category');

            $table->uuid('participation_type_id')->comment('FK to participation_type (mandatory or voluntary).');
            $table->foreign('participation_type_id')
                ->references('id')
                ->on('participation_type');

            $table->uuid('amount_mode_id')->comment('FK to amount_mode (fixed, range, free, etc.).');
            $table->foreign('amount_mode_id')
                ->references('id')
                ->on('amount_mode');

            // Money columns (minor units, nullable — some modes don't use all)
            $table->bigInteger('fixed_amount')->nullable()->comment('Minor units. Used for fixed mode.');
            $table->bigInteger('min_amount')->nullable()->comment('Minor units. Used for range mode.');
            $table->bigInteger('ideal_amount')->nullable()->comment('Minor units. Used for range mode.');
            $table->bigInteger('max_amount')->nullable()->comment('Minor units. Used for range mode.');

            $table->boolean('allow_decline')->default(true)->comment('Only relevant for voluntary projects.');

            // Target (reuse Collections pattern)
            $table->string('target_type')->default('all')->comment('Target scope strategy: all, by_type, by_tower, by_floor, manual, etc.');
            $table->jsonb('target_selector')->nullable()->comment('JSON payload that narrows the target scope (towers, floors, unit types).');

            // Financial goal
            $table->bigInteger('goal_amount')->nullable()->comment('Minor units. Optional total collection goal.');

            // Dates
            $table->date('start_date')->nullable()->comment('Date the project contributions open.');
            $table->date('due_date')->nullable()->comment('Date by which contributions are expected to be collected.');

            // Responsible (central user, withoutGlobalScope)
            $table->uuid('responsible_user_id')->nullable()->index()->comment('FK to user responsible for managing this project (central scope).');

            // Visibility & settings
            $table->boolean('visible_to_residents')->default(true)->comment('Whether residents can see this project in the app.');
            $table->boolean('allow_comments')->default(true)->comment('Whether residents are allowed to post comments on this project.');

            $table->uuid('community_project_status_id')->comment('FK to community_project_status.');
            $table->foreign('community_project_status_id')
                ->references('id')
                ->on('community_project_status');

            // Unique title per tenant (soft-delete aware via partial index)
            $table->index('community_project_status_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('community_project');
    }
};
