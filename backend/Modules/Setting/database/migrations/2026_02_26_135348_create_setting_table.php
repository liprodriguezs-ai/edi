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
        Schema::create('setting', function (Blueprint $table) {
            BaseModel::columns($table, true);
            $table->string('name')->comment('Human-readable label of the setting');
            $table->string('description')->nullable()->comment('Optional explanation of what this setting controls');
            $table->string('slug')->comment('Machine-readable key unique per tenant; used to retrieve the setting by code');
            $table->text('value')->nullable()->comment('Stored setting value (serialized per type)');
            $table->string('type')->default('string')->comment('Value type for deserialization: string | boolean | integer | json');
            $table->unique(['tenant_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('setting');
    }
};
