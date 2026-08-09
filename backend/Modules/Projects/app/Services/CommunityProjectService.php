<?php

namespace Modules\Projects\Services;

use App\Services\RichTextSanitizer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Modules\Projects\Models\CommunityProject;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * CommunityProjectService — rich-text description + document attachments for
 * community projects. Mirrors AnnouncementService (dual content/content_html
 * field + the 5-file attachment limit) so both modules share one shape.
 */
class CommunityProjectService
{
    public const MAX_DOCUMENTS = 5;

    /**
     * Apply the project description from the request, supporting the dual field:
     *
     *  - description_html present & non-empty → sanitize it into description_html
     *    (set DIRECTLY, never mass-assignable) and DERIVE the plain-text description.
     *  - description_html present but empty/null → clear BOTH the rich HTML and its
     *    derived plain text (the description is optional for a project).
     *  - description_html absent → leave description/description_html untouched (a
     *    partial update that never mentions the description keeps it as-is).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function applyRichTextContent(CommunityProject $project, array $attributes): void
    {
        if (! array_key_exists('description_html', $attributes)) {
            return;
        }

        $html = $attributes['description_html'];

        if (is_string($html) && trim($html) !== '') {
            $clean = RichTextSanitizer::sanitize($html);

            $project->description_html = $clean;
            $project->description = RichTextSanitizer::toPlainText($clean);

            return;
        }

        // Key present but empty/null → clear the rich description entirely; the
        // derived plain text goes with it (description is optional).
        $project->description_html = null;
        $project->description = null;
    }

    /**
     * Attach a file to the project's `documents` collection.
     * Max 5 files per project and max 10 MB per file — a 6th file is REJECTED,
     * never silently rotated out.
     *
     * @throws \InvalidArgumentException when limits are exceeded.
     */
    public function attachDocument(CommunityProject $project, UploadedFile $file): Media
    {
        $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

        if (! in_array(strtolower($file->getClientOriginalExtension()), $allowed, true)) {
            throw new \InvalidArgumentException('Tipo de archivo no permitido.');
        }

        $maxBytes = 10 * 1024 * 1024; // 10 MB

        if ($file->getSize() > $maxBytes) {
            throw new \InvalidArgumentException('El archivo supera el límite de 10 MB.');
        }

        $attachedCount = $project->media()
            ->where('collection_name', 'documents')
            ->count();

        if ($attachedCount >= self::MAX_DOCUMENTS) {
            throw new \InvalidArgumentException('Máximo 5 documentos por proyecto.');
        }

        return $project->addMedia($file)
            ->toMediaCollection('documents');
    }

    /**
     * Remove a document from the project's `documents` collection.
     *
     * The media MUST belong to this project's documents collection — a mismatched
     * or foreign media id is a 404 (never deletes another entity's file).
     *
     * @throws ModelNotFoundException when the media is not a document of this project.
     */
    public function detachDocument(CommunityProject $project, string $mediaId): void
    {
        $media = $project->media()
            ->where('id', $mediaId)
            ->where('collection_name', 'documents')
            ->first();

        if ($media === null) {
            throw new ModelNotFoundException('Documento no encontrado.');
        }

        $media->delete();
    }
}
