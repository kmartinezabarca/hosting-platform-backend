<?php

namespace Tests\Unit;

use App\Domains\Platform\Services\Coolify\CoolifyService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * createApplication() separa la imagen Docker en nombre + tag para el endpoint
 * /applications/dockerimage de Coolify. El método splitDockerImage() faltaba y
 * rompía TODO el aprovisionamiento de hosting (fatal "undefined method").
 * Este test blinda el parseo para que no vuelva a romperse.
 */
class CoolifyDockerImageTest extends TestCase
{
    private function split(string $image): array
    {
        $service = app(CoolifyService::class);
        $method = new ReflectionMethod($service, 'splitDockerImage');
        $method->setAccessible(true);

        return $method->invoke($service, $image);
    }

    public function test_splits_simple_image_with_tag(): void
    {
        $this->assertSame(['nginx', 'alpine'], $this->split('nginx:alpine'));
    }

    public function test_splits_namespaced_image_with_tag(): void
    {
        $this->assertSame(
            ['serversideup/php', '8.2-fpm-nginx'],
            $this->split('serversideup/php:8.2-fpm-nginx'),
        );
    }

    public function test_defaults_to_latest_when_no_tag(): void
    {
        $this->assertSame(['nginx', 'latest'], $this->split('nginx'));
    }

    public function test_ignores_registry_port_colon(): void
    {
        // El ':' del puerto del registro no debe tomarse como tag.
        $this->assertSame(['registry:5000/img', 'latest'], $this->split('registry:5000/img'));
        $this->assertSame(['registry:5000/img', 'v2'], $this->split('registry:5000/img:v2'));
    }
}
