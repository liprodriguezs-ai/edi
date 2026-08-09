<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collaborator', function (Blueprint $table) {
            $table->string('name')->nullable()->after('user_id')->comment('First name');
            $table->string('last_name')->nullable()->after('name')->comment('First surname');
            $table->string('second_last_name')->nullable()->after('last_name')->comment('Second surname');
            $table->string('email')->nullable()->after('second_last_name')->comment('Contact email address');
            $table->string('phone')->nullable()->after('email')->comment('Contact phone number');
        });
    }

    public function down(): void
    {
        Schema::table('collaborator', function (Blueprint $table) {
            $table->dropColumn(['name', 'last_name', 'second_last_name', 'email', 'phone']);
        });
    }
};
