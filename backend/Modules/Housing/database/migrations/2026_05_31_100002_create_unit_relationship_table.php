<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_relationship', function (Blueprint $table) {
            $table->comment('Catalog of resident-to-unit relationship types (global, not tenant-scoped)');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Relationship type label (e.g. owner, renter, co_owner, family_resident, billing_contact)');
            $table->integer('order')->default(0)->comment('Display sort order');
            $table->boolean('is_active')->default(true)->comment('Whether this relationship type is available for selection');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_relationship');
    }
};
