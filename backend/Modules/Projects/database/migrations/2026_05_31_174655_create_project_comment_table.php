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
        Schema::create('project_comment', function (Blueprint $table) {
            BaseModel::columns($table, true);

            $table->uuid('community_project_id')->comment('FK to community_project.');
            $table->foreign('community_project_id')
                ->references('id')
                ->on('community_project')
                ->cascadeOnDelete();

            // Central user — withoutGlobalScope
            $table->uuid('user_id')->index()->comment('FK to user who posted the comment (central scope, no tenant filter).');

            // Threading (self-referential FK added after table creation below)
            $table->uuid('parent_id')->nullable()->index()->comment('FK to parent project_comment for threaded replies; null for top-level comments.');

            $table->text('body')->comment('Comment text content.');
            $table->boolean('hidden')->default(false)->comment('Soft-hide flag; hidden comments are never physically deleted.');

            $table->index('community_project_id');
        });

        // Self-referential FK must be added after the table is fully created.
        Schema::table('project_comment', function (Blueprint $table) {
            $table->foreign('parent_id')
                ->references('id')
                ->on('project_comment')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_comment');
    }
};
