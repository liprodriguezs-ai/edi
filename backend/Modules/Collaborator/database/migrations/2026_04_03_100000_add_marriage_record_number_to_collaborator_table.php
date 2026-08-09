<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collaborator', function (Blueprint $table) {
            $table->string('marriage_record_number')->nullable()->after('marital_status_id')->comment('Official marriage registry number');
        });
    }

    public function down(): void
    {
        Schema::table('collaborator', function (Blueprint $table) {
            $table->dropColumn('marriage_record_number');
        });
    }
};
