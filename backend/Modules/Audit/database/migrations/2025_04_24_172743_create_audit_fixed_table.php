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
        Schema::create('audit_fixed', function (Blueprint $table) {
            $morphPrefix = config('audit.user.morph_prefix', 'user');

            BaseModel::columns($table);
            $table->string($morphPrefix.'_type')->nullable()->comment('Morph type of the user who performed the action');
            $table->uuid($morphPrefix.'_id')->nullable()->comment('ID of the user who performed the action');
            $table->string('event')->comment('Audit event name: created | updated | deleted | restored');
            $table->uuidMorphs('auditable');
            $table->longText('old_values')->nullable()->comment('JSON snapshot of attribute values before the change');
            $table->longText('new_values')->nullable()->comment('JSON snapshot of attribute values after the change');
            $table->longText('url')->nullable()->comment('Request URL that triggered the audit event');
            $table->ipAddress('ip_address')->nullable()->comment('IP address of the client that made the request');
            $table->string('user_agent', 1023)->nullable()->comment('User-agent string of the client browser or API caller');
            $table->string('tags')->nullable()->comment('Comma-separated tags for grouping or filtering audit records');

            $table->index([$morphPrefix.'_id', $morphPrefix.'_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_fixed');
    }
};
