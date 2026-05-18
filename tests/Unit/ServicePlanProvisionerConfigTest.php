<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\ServicePlanController;
use App\Models\ServicePlan;
use ReflectionMethod;
use Tests\TestCase;

class ServicePlanProvisionerConfigTest extends TestCase
{
    public function test_hestia_top_level_fields_are_normalized_into_config(): void
    {
        $payload = $this->normalize([
            'provisioner' => 'hestia',
            'hestia_package' => 'hosting-enterprise',
            'hestia_web_template' => 'default',
            'hestia_dns_template' => 'default',
            'hestia_mail_enabled' => true,
            'hestia_db_enabled' => false,
        ]);

        $this->assertSame('hestia', $payload['provisioner']);
        $this->assertSame('hosting-enterprise', $payload['provisioner_config']['package']);
        $this->assertTrue($payload['provisioner_config']['mail_enabled']);
        $this->assertFalse($payload['provisioner_config']['db_enabled']);
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
            'provisioner' => 'hestia',
            'hestia_package' => 'hosting-enterprise',
        ]);

        $serialized = $this->serialize($plan);

        $this->assertSame('hestia', $serialized['provisioner']);
        $this->assertSame('hosting-enterprise', $serialized['provisioner_config']['package']);
        $this->assertSame('hosting-enterprise', $serialized['hestia_package']);
        $this->assertSame('default', $serialized['hestia_web_template']);
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
