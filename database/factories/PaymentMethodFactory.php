<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid'        => (string) Str::uuid(),
            'user_id'     => User::factory(),
            // Actual DB columns (no stripe_customer_id / stripe_payment_method_id columns in base table)
            'type'        => 'card',
            'provider'    => 'stripe',
            'provider_id' => 'pm_test_' . Str::random(16),
            'name'        => 'Visa •••• ' . fake()->numerify('####'),
            'details'     => [
                'brand'     => 'visa',
                'last_four' => fake()->numerify('####'),
                'exp_month' => fake()->numberBetween(1, 12),
                'exp_year'  => fake()->numberBetween(2025, 2030),
            ],
            'is_default' => false,
            'is_active'  => true,
            'expires_at' => now()->addYears(2),
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => ['is_default' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
