<?php

namespace Modules\User\Transformers;

use Illuminate\Http\Request;
use Orion\Http\Resources\Resource;

class UseResource extends Resource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $item = parent::toArray($request);
        $user = $this->resource;

        // Cargar explícitamente la relación de roles
        $user->load('roles');

        return $item;
    }
}
