<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Services\MediaAuthorizationService;
use App\Services\MediaDownloadUrlService;
use App\Services\MediaStreamDownloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Tenant\Models\Tenant;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    // Descarga genérica de archivos protegida por auth:sanctum e initializeTenancy.
    // Recibe el UUID como string y busca manualmente para evitar que Laravel
    // busque en la base de datos central antes de inicializar el tenant.

    public function __construct(
        private MediaDownloadUrlService $mediaDownloadUrlService,
        private MediaAuthorizationService $mediaAuthorizationService,
        private MediaStreamDownloadService $mediaStreamDownloadService
    ) {}

    public function download(string $media): StreamedResponse
    {
        $mediaItem = Media::findOrFail($media);
        $this->mediaAuthorizationService->authorizeDownload($mediaItem);

        return $this->mediaStreamDownloadService->streamDownload($mediaItem);
    }

    public function downloadUrl(string $media): JsonResponse
    {
        $mediaItem = Media::findOrFail($media);
        $this->mediaAuthorizationService->authorizeDownload($mediaItem);

        $tenantId = tenancy()->initialized ? (string) tenancy()->tenant?->id : null;

        return response()->json(
            $this->mediaDownloadUrlService->resolveDownloadMeta($mediaItem, $tenantId)
        );
    }

    public function downloadSigned(Request $request, string $media): StreamedResponse
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'URL expirada o inválida.');
        }

        $initializedTenancyFromSignature = false;
        $tenantId = $request->query('tenant_id');
        if (is_string($tenantId) && trim($tenantId) !== '' && ! tenancy()->initialized) {
            $tenant = Tenant::find($tenantId);
            if ($tenant) {
                tenancy()->initialize($tenant);
                $initializedTenancyFromSignature = true;
            }
        }

        try {
            $mediaItem = Media::findOrFail($media);

            return $this->mediaStreamDownloadService->streamDownload($mediaItem);
        } finally {
            if ($initializedTenancyFromSignature && tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }
}
