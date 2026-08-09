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
        Schema::create('project_contribution', function (Blueprint $table) {
            BaseModel::columns($table, true);

            $table->uuid('community_project_id')->comment('FK to community_project.');
            $table->foreign('community_project_id')
                ->references('id')
                ->on('community_project')
                ->cascadeOnDelete();

            $table->uuid('unit_id')->comment('FK to unit making the contribution.');
            $table->foreign('unit_id')
                ->references('id')
                ->on('unit')
                ->cascadeOnDelete();

            $table->bigInteger('expected_amount')->nullable()->comment('Minor units. Null for free/voluntary-no-amount modes.');
            $table->bigInteger('contributed_amount')->default(0)->comment('Minor units. Denormalized sum of contributions.');

            $table->boolean('declined')->default(false)->comment('True when the unit resident explicitly declined to contribute (voluntary projects only).');

            $table->uuid('project_contribution_status_id')->comment('FK to project_contribution_status.');
            $table->foreign('project_contribution_status_id')
                ->references('id')
                ->on('project_contribution_status');

            $table->index('project_contribution_status_id');
            $table->index('unit_id');
            $table->unique(['tenant_id', 'community_project_id', 'unit_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_contribution');
    }
};
