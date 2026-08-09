<?php

namespace Modules\User\Transformers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Orion\Http\Resources\CollectionResource;

class UserCollectionResource extends CollectionResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $items = $this->resource;
        Log::info('Items: ');
        Log::info($items);

        return collect($items)->map(function ($user) {
            Log::info('UserCollectionResource: '.$user);
            Log::info($user);
            // Convertir el modelo a array
            if ($user != 1) {
                $item = $user->toArray();

                return $item;
            }
        })->toArray();
    }
}
