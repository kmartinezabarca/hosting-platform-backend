<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\ServicePlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ServicePlanFactory extends Factory
{
    protected $model = ServicePlan::class;

    public function definition(): array
    {
        return [
            'uuid' => Str::uuid()->toString(),
            'category_id' => Category::factory(),
            'slug' => Str::slug($this->faker->unique()->words(2, true)),
            'name' => ucwords($this->faker->unique()->words(2, true)),
            'description' => $this->faker->sentence(),
            'base_price' => $this->faker->randomFloat(2, 5, 100),
            'setup_fee' => $this->faker->randomFloat(2, 0, 25),
            'is_popular' => false,
            'is_active' => true,
            'sort_order' => $this->faker->numberBetween(1, 50),
            'specifications' => [],
        ];
    }

    public function popular(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_popular' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function pterodactyl(): static
    {
        return $this->state(fn (array $attributes) => [
            'provisioner' => 'pterodactyl',
            'pterodactyl_nest_id' => 1,
            'pterodactyl_egg_id' => 1,
            'pterodactyl_node_id' => 1,
            'pterodactyl_limits' => [
                'memory' => 1024,
                'cpu' => 100,
                'disk' => 1024,
            ],
        ]);
    }
}
