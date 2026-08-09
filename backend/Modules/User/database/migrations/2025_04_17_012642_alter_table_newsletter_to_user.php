<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->boolean('receive_newsletter')->default(0)->comment('Whether the user has opted in to receive the newsletter.');
        });

        if (Schema::hasTable('prospect')) {
            Schema::table('prospect', function (Blueprint $table) {
                $table->boolean('receive_newsletter')->default(0)->comment('Whether the prospect has opted in to receive the newsletter.');
            });
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->dropColumn('receive_newsletter');
        });
    }
};
