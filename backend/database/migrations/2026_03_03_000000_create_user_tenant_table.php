<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_tenant', function (Blueprint $table) {
            $table->foreignUuid('user_id')->references('id')->on('user')->cascadeOnDelete()->comment('FK to user');
            $table->foreignUuid('tenant_id')->references('id')->on('tenant')->cascadeOnDelete()->comment('FK to tenant (organization)');
            $table->timestamps();

            $table->primary(['user_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tenant');
    }
};
