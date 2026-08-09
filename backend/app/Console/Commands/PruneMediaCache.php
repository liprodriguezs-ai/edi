<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PruneMediaCache extends Command
{
    protected $signature = 'media:prune-cache';

    protected $description = 'Elimina archivos vencidos de la caché local de media cloud (storage/app/private/media_cache/).';

    public function handle(): int
    {
        $cacheDir = storage_path('app/private/media_cache');

        if (! is_dir($cacheDir)) {
            $this->info('La carpeta de caché no existe. Nada que limpiar.');

            return self::SUCCESS;
        }

        $ttlSeconds = (int) config('media-library.cache_ttl_hours', 24) * 3600;
        $now = time();
        $deleted = 0;
        $errors = 0;

        /** @var \SplFileInfo $file */
        foreach (new \FilesystemIterator($cacheDir) as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $age = $now - $file->getMTime();

            if ($age >= $ttlSeconds) {
                if (@unlink($file->getPathname())) {
                    $deleted++;
                } else {
                    $this->warn("No se pudo eliminar: {$file->getFilename()}");
                    $errors++;
                }
            }
        }

        $this->info("Caché de media purgada: {$deleted} archivo(s) eliminado(s), {$errors} error(es).");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
