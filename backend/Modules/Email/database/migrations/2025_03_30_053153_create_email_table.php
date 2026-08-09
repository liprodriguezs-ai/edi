<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('email', function (Blueprint $table) {
            BaseModel::columns($table, true);
            $table->string('subject')->comment('Default subject line used when sending this email template');
            $table->string('slug')->comment('Machine-readable key identifying this email template (e.g. welcome, password_reset)');
            $table->string('template')->comment('Name or path of the Blade / React Email template file to render');
            // $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email');
    }
};
