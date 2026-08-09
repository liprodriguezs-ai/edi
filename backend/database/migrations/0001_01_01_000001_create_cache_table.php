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
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary()->comment('Cache item key');
            $table->mediumText('value')->comment('Serialized cached value');
            $table->integer('expiration')->comment('Unix timestamp when the cache entry expires');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary()->comment('Lock key identifier');
            $table->string('owner')->comment('Owner token that acquired the lock');
            $table->integer('expiration')->comment('Unix timestamp when the lock expires');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
