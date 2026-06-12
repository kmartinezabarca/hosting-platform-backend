<?php

namespace Database\Factories;

use App\Domains\Platform\Compute\Enums\ResourceKind;
use App\Domains\Platform\Compute\Enums\ResourceStatus;
use App\Domains\Platform\Compute\Models\Environment;
use App\Domains\Platform\Compute\Models\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;

class ResourceFactory extends Factory
{
    protected $model = Resource::class;

    public function definition(): array
    {
        return [
            'environment_id' => Environment::factory(),
            'kind'           => ResourceKind::App,
            'name'           => $this->faker->word(),
            'status'         => ResourceStatus::Creating,
            'spec'           => ['ram_mb' => 512, 'cpu' => 0.5],
        ];
    }
}
