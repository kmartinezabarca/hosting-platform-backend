<?php

namespace Database\Factories;

use App\Domains\Platform\Compute\Enums\TeamRole;
use App\Domains\Platform\Compute\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TeamFactory extends Factory
{
    protected $model = Team::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name'          => $name,
            'slug'          => Str::slug($name) . '-' . Str::lower(Str::random(4)),
            'owner_user_id' => User::factory(),
            'plan_tier'     => 'free',
            'is_personal'   => false,
        ];
    }

    public function personal(): static
    {
        return $this->state(fn () => ['is_personal' => true]);
    }

    /** Crea además la fila de membresía owner (como hace el backfill). */
    public function configure(): static
    {
        return $this->afterCreating(function (Team $team) {
            $team->members()->syncWithoutDetaching([
                $team->owner_user_id => ['role' => TeamRole::Owner->value],
            ]);
        });
    }
}
