<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

class CreateAuditsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $connection = config('audit.drivers.database.connection', config('database.default'));
        $table = config('audit.drivers.database.table', 'audits');

        Schema::connection($connection)->create($table, function (Blueprint $table) {

            $morphPrefix = config('audit.user.morph_prefix', 'user');

            BaseModel::columns($table);
            $table->string($morphPrefix.'_type')->nullable()->comment('Morph type of the user who performed the action');
            $table->uuid($morphPrefix.'_id')->nullable()->comment('UUID of the user who performed the action');
            $table->string('event')->comment('Audit event type (created, updated, deleted, etc.)');
            $table->uuidMorphs('auditable');
            $table->longText('old_values')->nullable()->comment('JSON snapshot of old field values before the change');
            $table->longText('new_values')->nullable()->comment('JSON snapshot of new field values after the change');
            $table->longText('url')->nullable()->comment('URL of the request that triggered the audit event');
            $table->ipAddress('ip_address')->nullable()->comment('Client IP address at the time of the event');
            $table->string('user_agent', 1023)->nullable()->comment('HTTP User-Agent string of the client');
            $table->string('tags')->nullable()->comment('Optional comma-separated tags for filtering audits');

            $table->index([$morphPrefix.'_id', $morphPrefix.'_type']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $connection = config('audit.drivers.database.connection', config('database.default'));
        $table = config('audit.drivers.database.table', 'audits');

        Schema::connection($connection)->drop($table);
    }
}
