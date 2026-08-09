<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcement', function (Blueprint $table): void {
            $table->text('content_html')
                ->nullable()
                ->after('content')
                ->comment('Sanitized rich-text HTML; content holds the plain-text strip used for search + notification excerpts.');
        });
    }

    public function down(): void
    {
        Schema::table('announcement', function (Blueprint $table): void {
            $table->dropColumn('content_html');
        });
    }
};
