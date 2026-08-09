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
        Schema::create('access_log', function (Blueprint $table): void {
            $table->comment('Immutable log of cluster access entries and exits');
            BaseModel::columns($table, true);
            $table->foreignUuid('visitor_id')->nullable()->constrained('visitor')->nullOnDelete()->comment('FK to visitor — pre-registered visitor record; null for unregistered walk-ins.');
            $table->foreignUuid('unit_id')->constrained('unit')->comment('FK to unit — the residential unit being visited.');
            $table->string('visitor_name')->comment('Name of the visitor as recorded at entry time (immutable snapshot).');
            $table->foreignUuid('access_entry_type_id')->constrained('access_entry_type')->comment('FK to access_entry_type — how the visitor entered (pedestrian, vehicle, etc.).');
            $table->foreignUuid('access_verification_id')->constrained('access_verification')->comment('FK to access_verification — identity verification method used by the guard.');
            $table->string('authorized_by')->nullable()->comment('Name or identifier of the person who authorised the entry.');
            $table->timestamp('entered_at')->comment('Timestamp when the visitor entered the cluster.');
            $table->timestamp('exited_at')->nullable()->comment('Timestamp when the visitor exited the cluster; null if still inside.');
            $table->uuid('guard_user_id')->nullable()->index()->comment('FK to user — security guard who recorded this log entry.');
            $table->text('correction')->nullable()->comment('Free-text note explaining any post-entry correction made to this record.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('access_log');
    }
};
