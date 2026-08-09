<?php

namespace Modules\Setting\Services;

use Modules\Setting\Models\Setting;

class SettingService
{
    /**
     * Devuelve todos los settings como slug => valor resuelto (para app-config / features).
     */
    public static function getPublicKeyValue(): array
    {
        return Setting::query()
            ->orderBy('slug')
            ->get()
            ->mapWithKeys(fn (Setting $s) => [$s->slug => $s->resolved_value])
            ->all();
    }
}
