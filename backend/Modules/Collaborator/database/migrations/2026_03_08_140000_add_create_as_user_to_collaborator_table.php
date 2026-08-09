<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collaborator', function (Blueprint $table) {
            $table->boolean('create_as_user')->default(false)->after('phone')->comment('Whether a linked user account was created for this employee');
        });
    }

    public function down(): void
    {
        Schema::table('collaborator', function (Blueprint $table) {
            $table->dropColumn('create_as_user');
        });
    }
};
