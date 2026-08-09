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
        Schema::create('board_member', function (Blueprint $table) {
            $table->comment('Assignment of a user to a directorate role within a board period; grants the corresponding Spatie role on creation.');
            BaseModel::columns($table, true);
            $table->foreignUuid('board_period_id')->constrained('board_period')->comment('FK to board_period this assignment belongs to.');
            $table->uuid('user_id')->index()->comment('FK to user assigned to this directorate role.');
            $table->foreignUuid('board_role_id')->constrained('board_role')->comment('FK to board_role defining the position and Spatie slug.');
            // unit_id is a reference to the housing unit — stored as a bare UUID column
            // (no FK across module boundaries; mirrors the UnitUser pattern)
            $table->uuid('unit_id')->nullable()->index()->comment('Reference to the housing unit associated with this member; no cross-module FK enforced.');
            $table->unique(['tenant_id', 'board_period_id', 'user_id', 'board_role_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_member');
    }
};
