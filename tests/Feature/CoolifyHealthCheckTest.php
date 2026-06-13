<?php

namespace Tests\Feature;

use App\Domains\Platform\Services\Coolify\CoolifyService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bloquea contra regresiones el cableado del health check: toda app creada en
 * Coolify debe llevar el bloque de health check derivado de config('coolify.health_check').
 * Sin esto, los contenedores nacen como "running:unknown" (Coolify no sabe
 * cuándo el sitio está sano) en vez de "running:healthy".
 */
class CoolifyHealthCheckTest extends TestCase
{
    public function test_create_application_sends_health_check_payload_from_config(): void
    {
        config([
            'coolify.base_url'             => 'http://coolify.test',
            'coolify.api_token'            => 'token',
            'coolify.server_uuid'          => 'srv-1',
            'coolify.health_check.enabled' => true,
            'coolify.health_check.path'    => '/',
            'coolify.health_check.method'  => 'GET',
        ]);

        Http::fake([
            '*/api/v1/applications/dockerimage' => Http::response(['uuid' => 'app-123'], 201),
        ]);

        (new CoolifyService())->createApplication([
            'project_uuid' => 'proj-1',
            'server_uuid'  => 'srv-1',
            'name'         => 'demo',
            'build_pack'   => 'static',
            'fqdn'         => 'https://demo.rokeindustries.dev',
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v1/applications/dockerimage')
                && ($request['health_check_enabled'] ?? null) === true
                && ($request['health_check_path'] ?? null) === '/'
                && ! empty($request['health_check_port']);
        });
    }

    public function test_health_check_can_be_disabled_via_config(): void
    {
        config([
            'coolify.base_url'             => 'http://coolify.test',
            'coolify.api_token'            => 'token',
            'coolify.server_uuid'          => 'srv-1',
            'coolify.health_check.enabled' => false,
        ]);

        Http::fake([
            '*/api/v1/applications/dockerimage' => Http::response(['uuid' => 'app-123'], 201),
        ]);

        (new CoolifyService())->createApplication([
            'project_uuid' => 'proj-1',
            'name'         => 'demo',
            'build_pack'   => 'static',
        ]);

        Http::assertSent(fn ($request) => ($request['health_check_enabled'] ?? null) === false);
    }
}
