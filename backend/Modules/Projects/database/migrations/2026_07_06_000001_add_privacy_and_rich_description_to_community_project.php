<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_project', function (Blueprint $table) {
            $table->boolean('payments_visible_to_residents')
                ->default(false)
                ->after('allow_comments')
                ->comment('When false, residents only see their own units contribution rows; the board always sees all.');

            $table->text('description_html')
                ->nullable()
                ->after('allow_comments')
                ->comment('Sanitized rich-text description (HTML); description keeps the derived plain text.');
        });
    }

    public function down(): void
    {
        Schema::table('community_project', function (Blueprint $table) {
            $table->dropColumn(['payments_visible_to_residents', 'description_html']);
        });
    }
};
