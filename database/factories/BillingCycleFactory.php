<?php

namespace Database\Factories;

use App\Models\BillingCycle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BillingCycleFactory extends Factory
{
    protected $model = BillingCycle::class;

    public function definition(): array
    {
        return [
            'uuid' => Str::uuid()->toString(),
            'slug' => $this->faker->unique()->randomElement(['monthly', 'quarterly', 'semiannually', 'annually']),
            'name' => $this->faker->randomElement(['Monthly', 'Quarterly', 'Semi-Annually', 'Annually']),
            'months' => $this->faker->randomElement([1, 3, 6, 12]),
            'discount_percentage' => 0.00,
            'is_active' => true,
            'sort_order' => $this->faker->numberBetween(1, 10),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function withDiscount(?float $discount = 10.00): static
    {
        return $this->state(fn (array $attributes) => [
            'discount_percentage' => $discount ?? 10.00,
        ]);
    }
}
