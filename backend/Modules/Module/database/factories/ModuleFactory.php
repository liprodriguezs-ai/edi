<?php

namespace Modules\Module\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Module\Models\Module;

class ModuleFactory extends Factory
{
    protected $model = Module::class;

    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'icon' => 'IconBox',
            'visible' => true,
            'route' => "/{$slug}",
            'permission_entity' => $slug,
        ];
    }
}
