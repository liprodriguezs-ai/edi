<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_message', function (Blueprint $table): void {
            $table->text('body_html')
                ->nullable()
                ->after('body')
                ->comment('Sanitized rich-text HTML of the message; body holds the plain-text strip used for notifications/previews.');
        });
    }

    public function down(): void
    {
        Schema::table('chat_message', function (Blueprint $table): void {
            $table->dropColumn('body_html');
        });
    }
};
