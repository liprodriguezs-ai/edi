<?php

namespace Database\Seeders;

use App\Models\MaritalStatus;
use Illuminate\Database\Seeder;

class MaritalStatusTableSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            'Soltero',
            'Casado',
        ];

        foreach ($statuses as $status) {
            MaritalStatus::firstOrCreate(['name' => $status]);
        }
    }
}
