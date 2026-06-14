<?php

namespace Tests\Feature\GameServers;

use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Services\GameServers\Contracts\GameServerDriver;
use App\Domains\Platform\Services\ServiceSuspensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeGameServerDriver;
use Tests\TestCase;

/**
 * Fase 2 (migración): ServiceSuspensionService ya no depende de PterodactylService
 * directo, sino del contrato GameServerDriver. Mismo comportamiento, pero ahora
 * el panel es intercambiable. Se valida con el driver fake.
 */
class SuspensionViaDriverTest extends TestCase
{
    use RefreshDatabase;

    private FakeGameServerDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = new FakeGameServerDriver();
        $this->app->instance(GameServerDriver::class, $this->driver);
    }

    public function test_suspend_and_reactivate_go_through_the_driver(): void
    {
        $service = Service::factory()->create([
            'status'                => 'active',
            'pterodactyl_server_id' => 12345,
        ]);

        $svc = $this->app->make(ServiceSuspensionService::class);

        $svc->suspend($service);
        $this->assertTrue($this->driver->called('suspendServer'));
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertSame([12345], $this->driver->calls[0]['args']); // id reenviado tal cual

        $svc->reactivate($service);
        $this->assertTrue($this->driver->called('unsuspendServer'));
        $this->assertSame('active', $service->fresh()->status);
    }
}
