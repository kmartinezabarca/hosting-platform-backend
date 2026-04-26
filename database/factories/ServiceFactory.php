<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServicePlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'uuid' => Str::uuid()->toString(),
            'user_id' => User::factory(),
            'plan_id' => ServicePlan::factory(),
            'name' => $this->faker->words(3, true),
            'domain' => $this->faker->optional()->domainName(),
            'status' => $this->faker->randomElement(['active', 'pending', 'suspended']),
            'billing_cycle' => $this->faker->randomElement(['monthly', 'quarterly', 'annually']),
            'price' => $this->faker->randomFloat(2, 5, 100),
            'setup_fee' => $this->faker->randomFloat(2, 0, 25),
            'next_due_date' => $this->faker->dateTimeBetween('now', '+1 month'),
            'configuration' => [],
            'connection_details' => [],
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
        ]);
    }
}
