<?php

namespace Database\Factories;

use App\Domains\Platform\Models\Receipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Domains\Platform\Models\Receipt>
 */
class ReceiptFactory extends Factory
{
    protected $model = Receipt::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 10, 500);

        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'receipt_number' => config('app.receipt_prefix', 'REC-') . now()->format('Ym') . str_pad(fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'status' => Receipt::STATUS_SENT,
            'subtotal' => $subtotal,
            'tax_rate' => 16.00,
            'tax_amount' => round($subtotal * 0.16, 2),
            'total' => round($subtotal * 1.16, 2),
            'currency' => 'MXN',
            'due_date' => now()->addDays(30),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => Receipt::STATUS_PAID,
            'paid_at' => now(),
        ]);
    }

    public function unpaid(): static
    {
        return $this->state(fn () => [
            'status' => Receipt::STATUS_SENT,
        ]);
    }
}
