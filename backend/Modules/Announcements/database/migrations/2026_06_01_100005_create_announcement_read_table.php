<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('announcement_read')) {
            return;
        }

        Schema::create('announcement_read', function (Blueprint $table): void {
            $table->comment('Read receipt tracking which users have viewed a given announcement.');
            BaseModel::columns($table, true);
            $table->uuid('announcement_id')->comment('FK to announcement.');
            $table->uuid('user_id')->comment('FK to user who read the announcement.');
            $table->timestamp('read_at')->comment('Timestamp when the user first opened the announcement.');

            $table->foreign('announcement_id')->references('id')->on('announcement');
            // Unique per tenant + announcement + user (no double-read tracking)
            $table->unique(['tenant_id', 'announcement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_read');
    }
};
