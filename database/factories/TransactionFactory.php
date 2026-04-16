<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transaction>
 */
class TransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid'                   => (string) Str::uuid(),
            'user_id'                => User::factory(),
            'invoice_id'             => null,
            'payment_method_id'      => null,
            'transaction_id'         => 'txn_' . Str::random(16),
            'provider_transaction_id' => 'pi_test_' . Str::random(16),
            'type'                   => 'payment',  // ENUM: payment|refund|chargeback|fee
            'status'                 => 'pending',
            'amount'                 => fake()->randomFloat(2, 10, 500),
            'currency'               => 'MXN',
            'fee_amount'             => 0,
            'provider'               => 'stripe',
            'description'            => fake()->sentence(),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'       => 'completed',
            'processed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'         => 'failed',
            'failure_reason' => 'Card declined',
            'processed_at'   => now(),
        ]);
    }
}
