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
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->string('name')->comment('Human-readable token name (e.g., "API token")');
            $table->string('token', 64)->unique()->comment('Hashed token value (SHA-256)');
            $table->text('abilities')->nullable()->comment('Token abilities (scopes) as JSON array');
            $table->timestamp('last_used_at')->nullable()->comment('Timestamp of last token use');
            $table->timestamp('expires_at')->nullable()->comment('Optional expiry timestamp for the token');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
