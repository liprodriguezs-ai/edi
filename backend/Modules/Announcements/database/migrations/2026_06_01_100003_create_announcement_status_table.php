<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('announcement_status')) {
            return;
        }

        Schema::create('announcement_status', function (Blueprint $table): void {
            $table->comment('Catalog of announcement statuses (e.g. draft, published, expired).');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Human-readable status label.');
            $table->string('color')->nullable()->comment('Hex color used to render this status badge in the UI.');
            $table->integer('order')->default(0)->comment('Display sort order for this status.');
            $table->boolean('is_active')->default(true)->comment('Whether this status is assignable to announcements.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_status');
    }
};
