<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user', function (Blueprint $table) {
            BaseModel::columns($table);
            $table->string('name')->comment('Full first name of the user');
            // $table->string('language')->default('es');
            $table->string('last_name')->nullable()->comment('First surname');
            $table->string('second_last_name')->nullable()->comment('Second surname');
            $table->string('phone')->nullable()->comment('Phone number');
            $table->string('email')->unique()->comment('Email address (partial unique; null allowed for data-only users)');
            $table->timestamp('email_verified_at')->nullable()->comment('Timestamp when the email was verified');
            $table->string('verification_code', 6)->nullable()->comment('Short OTP code for email verification');
            $table->string('password')->nullable()->comment('Hashed password (nullable for users without credentials)');
            $table->rememberToken();
            $table->text('token_sender')->nullable()->comment('Token used by notification sender'); // agg
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary()->comment('Email address requesting the password reset');
            $table->string('token')->comment('Hashed password-reset token');
            $table->timestamp('created_at')->nullable()->comment('Timestamp when the reset token was generated');
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary()->comment('Session identifier');
            $table->foreignUuid('user_id')->nullable()->index()->constrained('user')->comment('FK to user');
            $table->string('ip_address', 45)->nullable()->comment('Client IP address (IPv4 or IPv6)');
            $table->text('user_agent')->nullable()->comment('HTTP User-Agent string');
            $table->longText('payload')->comment('Serialized session payload');
            $table->integer('last_activity')->index()->comment('Unix timestamp of last session activity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('sessions')->truncate();
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('user');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
        Schema::enableForeignKeyConstraints();
    }
};
