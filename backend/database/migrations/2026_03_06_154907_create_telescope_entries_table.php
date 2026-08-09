<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        return config('telescope.storage.database.connection');
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->create('telescope_entries', function (Blueprint $table) {
            $table->bigIncrements('sequence');
            $table->uuid('uuid')->comment('Unique identifier for this telescope entry');
            $table->uuid('batch_id')->comment('Groups entries that belong to the same request/batch');
            $table->string('family_hash')->nullable()->comment('Hash grouping related entries (e.g., for queries or exceptions)');
            $table->boolean('should_display_on_index')->default(true)->comment('Whether this entry appears in the Telescope dashboard index');
            $table->string('type', 20)->comment('Entry type (request, query, exception, mail, etc.)');
            $table->longText('content')->comment('JSON payload containing the full entry details');
            $table->dateTime('created_at')->nullable()->comment('Timestamp when the entry was recorded');

            $table->unique('uuid');
            $table->index('batch_id');
            $table->index('family_hash');
            $table->index('created_at');
            $table->index(['type', 'should_display_on_index']);
        });

        $schema->create('telescope_entries_tags', function (Blueprint $table) {
            $table->uuid('entry_uuid')->comment('FK to telescope_entries');
            $table->string('tag')->comment('Tag value associated with this entry (e.g., user ID, model class)');

            $table->primary(['entry_uuid', 'tag']);
            $table->index('tag');

            $table->foreign('entry_uuid')
                ->references('uuid')
                ->on('telescope_entries')
                ->cascadeOnDelete();
        });

        $schema->create('telescope_monitoring', function (Blueprint $table) {
            $table->string('tag')->primary()->comment('Tag being monitored in the Telescope dashboard');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->dropIfExists('telescope_entries_tags');
        $schema->dropIfExists('telescope_entries');
        $schema->dropIfExists('telescope_monitoring');
    }
};
