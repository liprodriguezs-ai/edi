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
            // $table->foreignUuid('municipality_id')->nullable()->references('id')->on('municipality');
            $table->string('birthdate')->nullable()->comment('Date of birth (stored as string, e.g. YYYY-MM-DD)');
            $table->string('gender')->nullable()->comment('Gender identity of the user');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            // $table->dropForeign(['municipality_id']);
            // $table->dropColumn('municipality_id');
            $table->dropColumn('birthdate');
            $table->dropColumn('gender');
        });
    }
};
