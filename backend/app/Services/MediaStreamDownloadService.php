<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaStreamDownloadService
{
    public function __construct(
        private MediaLocalPathResolver $mediaLocalPathResolver
    ) {}

    public function streamDownload(Media $mediaItem): StreamedResponse
    {
        return $this->buildStorageDownloadResponse($mediaItem);
    }

    private function buildStorageDownloadResponse(Media $mediaItem): StreamedResponse
    {
        $disk = Storage::disk($mediaItem->disk);
        $relativePath = $mediaItem->getPathRelativeToRoot();

        return response()->streamDownload(function () use ($disk, $relativePath) {
            $stream = $disk->readStream($relativePath);
            if ($stream === false) {
                throw new \RuntimeException('No se pudo leer el archivo desde el almacenamiento.');
            }

            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, $mediaItem->file_name, $this->downloadHeaders($mediaItem));
    }

    /**
     * @return array<string, string>
     */
    private function downloadHeaders(Media $mediaItem): array
    {
        return [
            'Content-Type' => $mediaItem->mime_type ?? 'application/octet-stream',
        ];
    }
}
