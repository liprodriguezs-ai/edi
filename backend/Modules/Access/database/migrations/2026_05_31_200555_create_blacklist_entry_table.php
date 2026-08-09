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
        Schema::create('blacklist_entry', function (Blueprint $table): void {
            $table->comment('Blacklist of visitors or vehicles denied access');
            BaseModel::columns($table, true);
            $table->foreignUuid('blacklist_type_id')->constrained('blacklist_type')->comment('FK to blacklist_type — category of entity being blacklisted.');
            $table->string('value')->comment('Blacklisted value (e.g. person name, plate number, or ID document).');
            $table->text('reason')->nullable()->comment('Explanation of why this entity was blacklisted.');
            $table->boolean('is_active')->default(true)->comment('Whether this blacklist entry is currently enforced.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blacklist_entry');
    }
};
