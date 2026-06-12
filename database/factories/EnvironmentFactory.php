<?php

namespace Database\Factories;

use App\Domains\Platform\Compute\Enums\EnvironmentType;
use App\Domains\Platform\Compute\Models\Environment;
use App\Domains\Platform\Compute\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class EnvironmentFactory extends Factory
{
    protected $model = Environment::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name'       => 'Production',
            'slug'       => 'production',
            'type'       => EnvironmentType::Production,
        ];
    }
}
