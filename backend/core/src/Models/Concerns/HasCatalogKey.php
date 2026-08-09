<?php

namespace QCH\Core\Models\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * Catálogos tenant-scoped con filas "de sistema".
 *
 * Una fila cuyo `key` no es null es requerida por lógica del backend (default
 * de un flujo automático: avisos de mesa directiva, conceptos de proyecto,
 * etc.). El mecanismo garantiza:
 *
 *  - Resolución estable por (tenant, key): idOfKey() TRUENA si la fila falta
 *    (jamás insertar un null silencioso); findByKey() para side-effects
 *    opcionales que prefieren omitirse a fallar.
 *  - Las filas de sistema no pueden eliminarse y su key es inmutable, así el
 *    administrador del organización renombra/recolorea sin romper los flujos.
 *  - `key` NO viaja al JSON (invisible para la UI); se expone `is_system`
 *    para que el frontend muestre candado y deshabilite el borrado.
 */
trait HasCatalogKey
{
    public static function bootHasCatalogKey(): void
    {
        static::deleting(function ($row): void {
            if ($row->key !== null) {
                throw ValidationException::withMessages([
                    'id' => ['Este registro es de sistema y no puede eliminarse.'],
                ]);
            }
        });

        static::updating(function ($row): void {
            if ($row->isDirty('key')) {
                $row->key = $row->getOriginal('key');
            }
        });
    }

    public function initializeHasCatalogKey(): void
    {
        $this->makeHidden('key');
        $this->append('is_system');
    }

    public static function findByKey(string $key): ?static
    {
        return static::query()->where('key', $key)->first();
    }

    /**
     * Id de la fila de sistema del tenant actual. Lanza ModelNotFoundException
     * si falta — eso es un error de seed/configuración que debe sonar fuerte,
     * nunca degradarse a un null silencioso en una FK.
     */
    public static function idOfKey(string $key): string
    {
        return static::query()->where('key', $key)->firstOrFail()->id;
    }

    public function getIsSystemAttribute(): bool
    {
        return $this->key !== null;
    }
}
