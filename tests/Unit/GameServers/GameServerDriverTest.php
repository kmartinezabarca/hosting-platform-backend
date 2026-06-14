<?php

namespace Tests\Unit\GameServers;

use App\Domains\Platform\Services\GameServers\Contracts\GameServerDriver;
use App\Domains\Platform\Services\GameServers\Drivers\PterodactylGameServerDriver;
use App\Domains\Platform\Services\Pterodactyl\PterodactylService;
use Mockery;
use Tests\TestCase;

/**
 * Seam del panel de juegos (fase 1): el contrato GameServerDriver se elige por
 * env y el adaptador Pterodactyl delega 1:1 en PterodactylService. Sin red.
 */
class GameServerDriverTest extends TestCase
{
    public function test_binding_resolves_pterodactyl_by_default(): void
    {
        // Evita construir el cliente real: el adaptador recibe un mock.
        $this->app->instance(PterodactylService::class, Mockery::mock(PterodactylService::class));

        $driver = $this->app->make(GameServerDriver::class);

        $this->assertInstanceOf(PterodactylGameServerDriver::class, $driver);
        $this->assertSame('pterodactyl', $driver->name());
    }

    public function test_invalid_driver_throws(): void
    {
        config(['compute.game_server.driver' => 'bogus']);

        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(GameServerDriver::class);
    }

    public function test_adapter_delegates_lifecycle_to_pterodactyl(): void
    {
        $ptero = Mockery::mock(PterodactylService::class);
        $ptero->shouldReceive('suspendServer')->once()->with(7);
        $ptero->shouldReceive('deleteServer')->once()->with(7, true);
        $ptero->shouldReceive('sendPowerSignal')->once()->with('abc123', 'restart');
        $ptero->shouldReceive('getServerResources')->once()->with('abc123')
            ->andReturn(['current_state' => 'running']);

        $driver = new PterodactylGameServerDriver($ptero);

        $driver->suspendServer(7);
        $driver->deleteServer(7);
        $driver->sendPowerSignal('abc123', 'restart');
        $this->assertSame(['current_state' => 'running'], $driver->getServerResources('abc123'));
    }
}
