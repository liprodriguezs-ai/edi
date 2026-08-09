<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

/**
 * Module 12 (§16.3.8): versioned legal documents of the cluster.
 *
 * Each upload is a NEW row (append-only versioning), so the history of
 * "reglamento interno", "acta constitutiva" and "aviso de privacidad" is kept.
 * The actual file lives in Spatie media (collection 'document' on this model),
 * UUID-keyed via App\Models\Media. `is_visible_to_residents` controls whether a
 * resident may download it (the aviso de privacidad is shown by default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_document', function (Blueprint $table): void {
            BaseModel::columns($table, true);
            $table->string('type')->comment('Document category: reglamento | acta_constitutiva | aviso_privacidad'); // reglamento | acta_constitutiva | aviso_privacidad
            $table->string('title')->nullable()->comment('Optional human-readable title for this version');
            $table->unsignedInteger('version')->default(1)->comment('Monotonically increasing version number per (tenant, type)');
            $table->boolean('is_current')->default(true)->comment('Whether this is the active version for the document type; partial unique enforced by DB index');
            $table->boolean('is_visible_to_residents')->default(false)->comment('Whether residents may download this document from the app');
            $table->timestamp('published_at')->nullable()->comment('Date the document was officially published / made effective');
            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_document');
    }
};
