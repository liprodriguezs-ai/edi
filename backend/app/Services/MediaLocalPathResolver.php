<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaLocalPathResolver
{
    /**
     * Devuelve una ruta local legible por herramientas que requieren archivo en disco (p. ej. PhpWord).
     *
     * - Se resuelve a una copia cacheada estable en storage/app/private/media_cache/.
     * - La caché dura MEDIA_CACHE_TTL_HOURS horas (por defecto 24).
     * - Si el archivo cacheado existe y no ha vencido → cache hit (no se descarga).
     * - Si no existe o venció → cache miss (se descarga y guarda sobre esa ruta).
     *
     * El archivo cacheado NO se elimina al terminar el request; se limpia mediante
     * el comando `media:prune-cache`, que corre diariamente a medianoche.
     */
    public function resolveLocalPath(Media $media): string
    {
        return $this->resolveFromCache($media);
    }

    /**
     * Retorna la ruta local cacheada del archivo.
     * Crea o reemplaza el archivo si no existe o si ya venció el TTL.
     */
    private function resolveFromCache(Media $media): string
    {
        $cachePath = $this->buildCachePath($media);

        if ($this->isCacheValid($cachePath)) {
            return $cachePath;
        }

        $this->downloadToCache($media, $cachePath);

        return $cachePath;
    }

    /**
     * Construye la ruta estable del archivo en caché.
     *
     * La clave usa media_id + sha1(updated_at) para invalidarse automáticamente
     * cuando el archivo original es reemplazado (updated_at cambia).
     */
    private function buildCachePath(Media $media): string
    {
        $cacheDir = storage_path('app/private/media_cache');

        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $extension = pathinfo($media->file_name, PATHINFO_EXTENSION);
        $suffix = $extension !== '' ? '.'.$extension : '';

        $updatedAtHash = sha1((string) $media->updated_at);
        $fileName = $media->id.'_'.$updatedAtHash.$suffix;

        return $cacheDir.'/'.$fileName;
    }

    /**
     * Verifica si la copia cacheada existe y aún no ha vencido el TTL.
     */
    private function isCacheValid(string $cachePath): bool
    {
        if (! file_exists($cachePath)) {
            return false;
        }

        $ttlSeconds = (int) config('media-library.cache_ttl_hours', 24) * 3600;

        return (time() - filemtime($cachePath)) < $ttlSeconds;
    }

    /**
     * Descarga el archivo desde cloud por stream y publica la caché
     * con rename atómico para evitar archivos parciales.
     */
    private function downloadToCache(Media $media, string $cachePath): void
    {
        $relativePath = $media->getPathRelativeToRoot();
        $sourceStream = Storage::disk($media->disk)->readStream($relativePath);
        if ($sourceStream === false) {
            throw new \RuntimeException('No se pudo abrir el archivo remoto para cachearlo.');
        }

        $temporaryPath = $cachePath.'.tmp.'.uniqid('', true);
        $targetStream = fopen($temporaryPath, 'wb');

        if ($targetStream === false) {
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }

            throw new \RuntimeException('No se pudo crear el archivo temporal de caché.');
        }

        try {
            $copiedBytes = stream_copy_to_stream($sourceStream, $targetStream);
            if ($copiedBytes === false) {
                throw new \RuntimeException('No se pudo copiar el archivo remoto a la caché local.');
            }
        } catch (\Throwable $exception) {
            @unlink($temporaryPath);
            throw $exception;
        } finally {
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }

            if (is_resource($targetStream)) {
                fclose($targetStream);
            }
        }

        if (! @rename($temporaryPath, $cachePath)) {
            @unlink($temporaryPath);

            throw new \RuntimeException('No se pudo publicar el archivo en la caché local.');
        }
    }
}
