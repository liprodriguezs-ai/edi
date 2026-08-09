<?php

namespace Modules\Board\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Board\Models\BoardMember;

class BoardMemberFactory extends Factory
{
    protected $model = BoardMember::class;

    public function definition(): array
    {
        return [
            'board_period_id' => null,
            'user_id' => null,
            'board_role_id' => null,
            'unit_id' => null,
        ];
    }
}
