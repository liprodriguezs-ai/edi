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
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index()->comment('Queue name the job belongs to');
            $table->longText('payload')->comment('Serialized job payload');
            $table->unsignedTinyInteger('attempts')->comment('Number of times the job has been attempted');
            $table->unsignedInteger('reserved_at')->nullable()->comment('Unix timestamp when the job was reserved by a worker');
            $table->unsignedInteger('available_at')->comment('Unix timestamp when the job becomes available to process');
            $table->unsignedInteger('created_at')->comment('Unix timestamp when the job was created');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary()->comment('Batch identifier');
            $table->string('name')->comment('Human-readable batch name');
            $table->integer('total_jobs')->comment('Total number of jobs in the batch');
            $table->integer('pending_jobs')->comment('Number of jobs still pending in the batch');
            $table->integer('failed_jobs')->comment('Number of failed jobs in the batch');
            $table->longText('failed_job_ids')->comment('JSON array of failed job IDs');
            $table->mediumText('options')->nullable()->comment('Serialized batch options');
            $table->integer('cancelled_at')->nullable()->comment('Unix timestamp when the batch was cancelled');
            $table->integer('created_at')->comment('Unix timestamp when the batch was created');
            $table->integer('finished_at')->nullable()->comment('Unix timestamp when the batch finished');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique()->comment('Unique identifier for the failed job');
            $table->text('connection')->comment('Queue connection name');
            $table->text('queue')->comment('Queue name the job failed on');
            $table->longText('payload')->comment('Serialized job payload at the time of failure');
            $table->longText('exception')->comment('Exception message and stack trace');
            $table->timestamp('failed_at')->useCurrent()->comment('Timestamp when the job failed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
    }
};
