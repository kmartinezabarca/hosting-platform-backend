<?php

namespace Tests\Unit;

use App\Domains\Platform\Http\Controllers\Admin\ServicePlanController;
use App\Domains\Platform\Models\ServicePlan;
use ReflectionMethod;
use Tests\TestCase;

class ServicePlanProvisionerConfigTest extends TestCase
{
    public function test_coolify_fields_are_normalized_into_config(): void
    {
        $payload = $this->normalize([
            'provisioner' => 'coolify',
            'provisioner_config' => [
                'build_pack' => 'php',
                'db_enabled' => true,
                'db_type'    => 'postgresql',
            ],
        ]);

        $this->assertSame('coolify', $payload['provisioner']);
        $this->assertSame('php', $payload['provisioner_config']['build_pack']);
        $this->assertTrue($payload['provisioner_config']['db_enabled']);
        $this->assertSame('postgresql', $payload['provisioner_config']['db_type']);
    }

    public function test_pterodactyl_legacy_fields_are_normalized_into_config(): void
    {
        $payload = $this->normalize([
            'provisioner' => 'pterodactyl',
            'pterodactyl_egg' => 'paper mc',
            'pterodactyl_version' => 'latest',
            'pterodactyl_environment' => ['MC_VERSION' => 'latest'],
        ]);

        $this->assertSame('paper mc', $payload['provisioner_config']['egg']);
        $this->assertSame('latest', $payload['provisioner_config']['version']);
        $this->assertSame(['MC_VERSION' => 'latest'], $payload['provisioner_config']['environment']);
        $this->assertSame(['MC_VERSION' => 'latest'], $payload['pterodactyl_environment']);
    }

    public function test_admin_serialization_returns_generic_and_legacy_fields(): void
    {
        $plan = new ServicePlan([
            'provisioner' => 'coolify',
            'provisioner_config' => ['build_pack' => 'php', 'db_enabled' => true],
        ]);

        $serialized = $this->serialize($plan);

        $this->assertSame('coolify', $serialized['provisioner']);
        $this->assertSame('php', $serialized['provisioner_config']['build_pack']);
        $this->assertSame('php', $serialized['coolify_build_pack']);
    }

    private function normalize(array $payload): array
    {
        $controller = app(ServicePlanController::class);
        $method = new ReflectionMethod($controller, 'normalizeProvisionerPayload');
        $method->setAccessible(true);

        return $method->invoke($controller, $payload);
    }

    private function serialize(ServicePlan $plan): array
    {
        $controller = app(ServicePlanController::class);
        $method = new ReflectionMethod($controller, 'serializePlan');
        $method->setAccessible(true);

        return $method->invoke($controller, $plan);
    }
}
