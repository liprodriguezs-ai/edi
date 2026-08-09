<?php

namespace Modules\Treasury\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;

class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company(),
            'bank_account_type_id' => BankAccountType::factory(),
            'bank_account_purpose_id' => null,
            'bank' => $this->faker->randomElement(['HSBC', 'Banorte', 'BBVA', 'Santander', null]),
            'account_number' => $this->faker->optional()->numerify('##########'),
            'clabe' => null,
            'currency' => 'MXN',
            'initial_balance' => $this->faker->numberBetween(0, 10000000),
            'is_primary' => false,
            'is_active' => true,
            'notes' => null,
        ];
    }

    public function withPurpose(): static
    {
        return $this->state(fn () => [
            'bank_account_purpose_id' => BankAccountPurpose::factory(),
        ]);
    }
}
