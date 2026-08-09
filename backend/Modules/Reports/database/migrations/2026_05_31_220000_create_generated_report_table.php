<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_report', function (Blueprint $table): void {
            BaseModel::columns($table, true); // tenant-scoped

            $table->string('report_key')->comment('Identifies which report was generated (maps to a registered report class)');
            $table->string('format')->default('json')->comment('Output format: json | pdf | excel'); // json | pdf | excel
            $table->json('config')->nullable()->comment('JSON configuration snapshot used to produce this report');
            $table->string('status')->default('pending')->comment('Generation status: pending | processing | ready | failed'); // pending | processing | ready | failed
            $table->string('file_path')->nullable()->comment('Storage path to the generated file (set when status = ready)');
            $table->uuid('generated_by_user_id')->nullable()->comment('User who triggered this report; no FK per project convention for central user'); // central User — no FK per project convention
            $table->timestamp('expires_at')->nullable()->comment('When the generated file will be purged from storage');
            $table->text('error')->nullable()->comment('Error message captured when status = failed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_report');
    }
};
