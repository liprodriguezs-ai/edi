<?php

namespace Modules\ChildRecord\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ChildRecord\Models\Caregiver;
use Modules\ChildRecord\Models\CaregiverRelationship;
use Modules\ChildRecord\Models\Child;
use Modules\ChildRecord\Models\ChildCaregiver;

/**
 * @extends Factory<ChildCaregiver>
 */
class ChildCaregiverFactory extends Factory
{
    protected $model = ChildCaregiver::class;

    public function definition(): array
    {
        return [
            'child_id' => fn () => Child::factory()->create()->id,
            'caregiver_id' => fn () => Caregiver::factory()->create()->id,
            'caregiver_relationship_id' => fn () => CaregiverRelationship::factory()->create()->id,
            'is_primary' => false,
        ];
    }
}
