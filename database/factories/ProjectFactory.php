<?php

namespace Database\Factories;

use App\Domains\Platform\Compute\Models\Project;
use App\Domains\Platform\Compute\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'team_id'        => Team::factory(),
            'name'           => $name,
            'slug'           => Str::slug($name),
            'default_branch' => 'main',
        ];
    }
}
