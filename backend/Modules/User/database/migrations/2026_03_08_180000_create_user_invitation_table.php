<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invitation', function (Blueprint $table) {
            if (env('DB_CONNECTION') === 'pgsql') {
                $table->uuid('id')->primary()->default(DB::raw('(uuid_generate_v4())'));
            } else {
                $table->uuid('id')->primary()->default(DB::raw('(UUID())'));
            }
            $table->string('token', 64)->unique()->comment('Unique secure token sent to the invitee via email.');
            $table->foreignUuid('tenant_id')->references('id')->on('tenant')->cascadeOnDelete()->comment('FK to tenant that issued the invitation.');
            $table->string('inviteable_type')->comment('Morph type of the inviting entity (e.g. App\\Models\\User).');
            $table->uuid('inviteable_id')->comment('Morph ID of the inviting entity.');
            $table->string('email')->comment('Email address the invitation was sent to.');
            $table->timestamp('expires_at')->nullable()->comment('Datetime when the invitation token expires.');
            $table->timestamps();

            $table->index(['inviteable_type', 'inviteable_id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitation');
    }
};
