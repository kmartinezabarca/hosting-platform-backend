<?php

namespace Tests\Unit;

use App\Domains\Platform\Services\Coolify\CoolifyHealthCheckPayload;
use App\Domains\Platform\Services\Coolify\CoolifyService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CoolifyHealthCheckPayloadTest extends TestCase
{
    public function test_builds_default_health_check_payload_for_coolify(): void
    {
        config(['coolify.health_check' => [
            'enabled' => true,
            'path' => '/',
            'method' => 'GET',
            'return_code' => 200,
            'scheme' => 'http',
            'interval' => 30,
            'timeout' => 10,
            'retries' => 3,
            'start_period' => 30,
        ]]);

        $payload = CoolifyHealthCheckPayload::forPort(80);

        $this->assertSame(true, $payload['health_check_enabled']);
        $this->assertSame('/', $payload['health_check_path']);
        $this->assertSame('80', $payload['health_check_port']);
        $this->assertSame('GET', $payload['health_check_method']);
        $this->assertSame(200, $payload['health_check_return_code']);
        $this->assertSame('http', $payload['health_check_scheme']);
        $this->assertSame(30, $payload['health_check_interval']);
        $this->assertSame(10, $payload['health_check_timeout']);
        $this->assertSame(3, $payload['health_check_retries']);
        $this->assertSame(30, $payload['health_check_start_period']);
    }

    public function test_create_application_sends_health_check_to_coolify(): void
    {
        config([
            'coolify.base_url' => 'https://coolify.test',
            'coolify.api_token' => 'token',
            'coolify.server_uuid' => 'server-1',
            'coolify.verify_ssl' => true,
            'coolify.health_check' => [
                'enabled' => true,
                'path' => '/',
                'method' => 'GET',
                'return_code' => 200,
                'scheme' => 'http',
                'interval' => 30,
                'timeout' => 10,
                'retries' => 3,
                'start_period' => 30,
            ],
        ]);

        Http::fake([
            '*' => Http::response(['uuid' => 'app-1'], 201),
        ]);

        (new CoolifyService)->createApplication([
            'project_uuid' => 'project-1',
            'name' => 'Hosting Enterprise',
            'build_pack' => 'static',
            'fqdn' => 'https://kmartinez.rokeindustries.com',
        ]);

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/api/v1/applications/dockerimage')
                && ($data['health_check_enabled'] ?? null) === true
                && ($data['health_check_path'] ?? null) === '/'
                && ($data['health_check_port'] ?? null) === '80'
                && ($data['health_check_method'] ?? null) === 'GET'
                && ($data['health_check_return_code'] ?? null) === 200
                && ($data['health_check_scheme'] ?? null) === 'http'
                && ($data['health_check_interval'] ?? null) === 30
                && ($data['health_check_timeout'] ?? null) === 10
                && ($data['health_check_retries'] ?? null) === 3
                && ($data['health_check_start_period'] ?? null) === 30;
        });
    }
}
