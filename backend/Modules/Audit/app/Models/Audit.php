<?php

namespace Modules\Audit\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;

// use \QCH\Core\Models\Audit;
#[ObservedBy([GlobalObserver::class])]
class Audit extends \QCH\Core\Models\Audit // BaseModel
{
    public function reportColumns(): array
    {
        return [
            'Fecha' => function ($result) {
                return $result->created_at ? $result->created_at->format('d/m/Y H:i') : '';
            },
            'Usuario' => function ($result) {
                return $result->user ? trim($result->user->name.' '.$result->user->last_name.' '.($result->user->second_last_name ?? '')) : '';
            },
            'Evento' => function ($result) {
                return $result->event;
            },
            'Entidad' => function ($result) {
                return $result->auditable_type ? class_basename($result->auditable_type) : '';
            },
            'ID de entidad' => function ($result) {
                return $result->auditable_id;
            },
            'Valores anteriores' => function ($result) {
                return $result->old_values ? json_encode($result->old_values, JSON_UNESCAPED_UNICODE) : '';
            },
            'Valores nuevos' => function ($result) {
                return $result->new_values ? json_encode($result->new_values, JSON_UNESCAPED_UNICODE) : '';
            },
            'IP' => function ($result) {
                return $result->ip_address;
            },
            'URL' => function ($result) {
                return $result->url;
            },
        ];
    }
}
