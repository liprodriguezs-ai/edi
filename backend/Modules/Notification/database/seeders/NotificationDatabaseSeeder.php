<?php

namespace Modules\Notification\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Notification\Models\Notification;

class NotificationDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // $this->call([]);
        Notification::updateOrCreate([
            'id' => Notification::REFER_WELCOME,
        ], [
            'title' => 'Red de patrocinadores',
            'message' => 'Felicidades, tu red sigue creciendo, se ha agregado una persona a tu red',
        ]);

        Notification::updateOrCreate([
            'id' => Notification::ORDER_DELETED_REQUEST,
        ], [
            'title' => 'Solicitud de cancelación de orden',
            'message' => 'Se ha solicitado la cancelación de una orden, por favor revisa el historial de cancelaciones',
        ]);

        Notification::updateOrCreate([
            'id' => Notification::ORDER_DELETED,
        ], [
            'title' => 'Orden cancelada',
            'message' => 'Se ha cancelado una orden',
        ]);

        Notification::updateOrCreate([
            'id' => Notification::USER_DELETED_REQUEST,
        ], [
            'title' => 'Solicitud de eliminación de usuario',
            'message' => 'Se ha solicitado la eliminación de un usuario, por favor revisa las solicitudes de eliminación',
        ]);

        Notification::updateOrCreate([
            'id' => Notification::USER_DELETED,
        ], [
            'title' => 'Usuario eliminado',
            'message' => 'Se ha eliminado un usuario',
        ]);
    }
}
