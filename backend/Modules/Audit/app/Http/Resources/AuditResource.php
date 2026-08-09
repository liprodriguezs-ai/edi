<?php

namespace Modules\Audit\Http\Resources;

use Illuminate\Http\Request;
use Orion\Http\Resources\Resource;

class AuditResource extends Resource
{
    /**
     * Transform the resource into an array.
     * Ensures old_values and new_values are always arrays in the API response.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        $data['old_values'] = $this->decodeJsonAttribute($data['old_values'] ?? null);
        $data['new_values'] = $this->decodeJsonAttribute($data['new_values'] ?? null);

        return $data;
    }

    /**
     * @param  mixed  $value
     * @return array<string, mixed>
     */
    private function decodeJsonAttribute($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
