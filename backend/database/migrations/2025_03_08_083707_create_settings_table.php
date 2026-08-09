<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up()
    {

        Schema::create('settings', function (Blueprint $table): void {
            BaseModel::columns($table);

            $table->string('group')->comment('Setting group/namespace (e.g., "general", "mail")');
            $table->string('name')->comment('Setting key within the group');
            $table->boolean('locked')->default(false)->comment('When true, the setting cannot be modified via UI');
            $table->json('payload')->comment('JSON value of the setting');

            $table->unique(['group', 'name']);
            $table->foreignUuid('user_id')->nullable()->constrained('user')->cascadeOnDelete()->comment('FK to user (owner of the setting, null for global settings)');
        });
    }

    public function down()
    {
        Schema::dropIfExists('settings');
    }
};
