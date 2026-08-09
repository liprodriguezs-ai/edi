<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class MediaDownloadUrlService
{
    private const DOWNLOAD_URL_EXPIRATION_MINUTES = 30;

    /**
     * @return array{download_url: string, expires_in: int, strategy: string}
     */
    public function resolveDownloadMeta(Media $mediaItem, ?string $tenantId = null): array
    {
        $relativePath = $mediaItem->getPathRelativeToRoot();
        $disk = Storage::disk($mediaItem->disk);

        $temporaryUrl = $this->tryGenerateStorageTemporaryUrl(
            $disk,
            $relativePath,
            $mediaItem->file_name,
            $mediaItem->mime_type
        );

        if ($temporaryUrl !== null) {
            return [
                'download_url' => $temporaryUrl,
                'expires_in' => self::DOWNLOAD_URL_EXPIRATION_MINUTES,
                'strategy' => 'storage_temporary_url',
            ];
        }

        $routeParams = [
            'media' => (string) $mediaItem->id,
        ];

        if (is_string($tenantId) && trim($tenantId) !== '') {
            $routeParams['tenant_id'] = $tenantId;
        }

        return [
            'download_url' => URL::temporarySignedRoute(
                'media.download_signed',
                now()->addMinutes(self::DOWNLOAD_URL_EXPIRATION_MINUTES),
                $routeParams
            ),
            'expires_in' => self::DOWNLOAD_URL_EXPIRATION_MINUTES,
            'strategy' => 'signed_route',
        ];
    }

    private function tryGenerateStorageTemporaryUrl(
        FilesystemAdapter $disk,
        string $relativePath,
        string $fileName,
        ?string $mimeType
    ): ?string {
        if (! method_exists($disk, 'temporaryUrl')) {
            return null;
        }

        try {
            return $disk->temporaryUrl(
                $relativePath,
                now()->addMinutes(self::DOWNLOAD_URL_EXPIRATION_MINUTES),
                $this->temporaryUrlOptions($fileName, $mimeType)
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function temporaryUrlOptions(string $fileName, ?string $mimeType): array
    {
        $options = [
            'ResponseContentDisposition' => $this->contentDispositionAttachment($fileName),
        ];

        if (is_string($mimeType) && trim($mimeType) !== '') {
            $options['ResponseContentType'] = $mimeType;
        }

        return $options;
    }

    private function contentDispositionAttachment(string $fileName): string
    {
        $escapedName = str_replace(['\\', '"'], ['\\\\', '\\"'], $fileName);

        return "attachment; filename=\"{$escapedName}\"; filename*=UTF-8''".rawurlencode($fileName);
    }
}
