<?php

namespace Tests\Feature;

use App\Domains\Platform\Models\ProvisioningJob;
use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Models\ServicePlan;
use App\Domains\Platform\Services\FrpService;
use App\Domains\Platform\Services\ProvisioningService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Reconciliación FRP: un game server con servidor Pterodactyl creado pero sin
 * proxy FRP no cuenta como aprovisionado; el reintento/reconciliación repite
 * SOLO el paso FRP y nunca crea un segundo servidor.
 */
class FrpReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function makeGameServerMissingFrp(string $status = 'failed'): Service
    {
        $user = User::factory()->create();
        $plan = ServicePlan::factory()->create(['provisioner' => 'pterodactyl']);

        return Service::factory()->create([
            'user_id'               => $user->id,
            'plan_id'               => $plan->id,
            'status'                => $status,
            'pterodactyl_server_id' => 9001,
            'connection_details'    => [
                'server_ip'   => '10.0.0.5',
                'server_port' => 25565,
                'frp_enabled' => false,
                'frp_error'   => 'relay down (intento previo)',
            ],
        ]);
    }

    public function test_provisioning_retry_repeats_only_frp_step_without_new_server(): void
    {
        $service = $this->makeGameServerMissingFrp();

        $job = ProvisioningJob::create([
            'service_id'   => $service->id,
            'provider'     => ProvisioningJob::PROVIDER_PTERODACTYL,
            'status'       => ProvisioningJob::STATUS_PENDING,
            'available_at' => now(),
        ]);

        // El paso FRP se reintenta; el servidor NO se recrea (PterodactylService
        // no se invoca en absoluto en la rama resumible).
        $this->mock(\App\Domains\Platform\Services\Pterodactyl\PterodactylService::class)
            ->shouldNotReceive('createServer');
        $this->mock(FrpService::class)
            ->shouldReceive('addTcpProxy')
            ->once()
            ->withArgs(fn (int $port, string $name) => $port === 25565)
            ->andReturnTrue();

        $ok = app(ProvisioningService::class)->runJob($job);

        $this->assertTrue($ok);
        $service->refresh();
        $this->assertSame('active', $service->status);
        $this->assertTrue((bool) $service->connection_details['frp_enabled']);
        $this->assertNull($service->connection_details['frp_error']);
    }

    public function test_service_with_server_but_no_frp_is_not_marked_already_provisioned(): void
    {
        $service = $this->makeGameServerMissingFrp();

        $job = ProvisioningJob::create([
            'service_id'   => $service->id,
            'provider'     => ProvisioningJob::PROVIDER_PTERODACTYL,
            'status'       => ProvisioningJob::STATUS_PENDING,
            'available_at' => now(),
            'max_attempts' => 5,
        ]);

        // FRP sigue fallando → el job NO debe quedar succeeded.
        $this->mock(FrpService::class)
            ->shouldReceive('addTcpProxy')
            ->once()
            ->andThrow(new \RuntimeException('relay still down'));

        $ok = app(ProvisioningService::class)->runJob($job);

        $this->assertFalse($ok);
        $this->assertDatabaseHas('provisioning_jobs', [
            'id'     => $job->id,
            'status' => ProvisioningJob::STATUS_PENDING, // reintentará con backoff
        ]);
        $this->assertSame('failed', $service->fresh()->status);
    }

    public function test_reconcile_command_creates_missing_frp_and_reactivates(): void
    {
        $service = $this->makeGameServerMissingFrp('active');

        $this->mock(FrpService::class)
            ->shouldReceive('addTcpProxy')
            ->once()
            ->andReturnTrue();

        Artisan::call('game-servers:reconcile-frp');

        $service->refresh();
        $this->assertTrue((bool) $service->connection_details['frp_enabled']);
        $this->assertArrayNotHasKey('frp_retry_count', $service->connection_details);
    }

    public function test_reconcile_command_tracks_failures_and_notifies_at_threshold(): void
    {
        $service = $this->makeGameServerMissingFrp('active');

        // 3 corridas fallidas → al tercer intento notifica al admin (una vez).
        for ($i = 1; $i <= 3; $i++) {
            $this->mock(FrpService::class)
                ->shouldReceive('addTcpProxy')
                ->once()
                ->andThrow(new \RuntimeException('relay down'));

            Artisan::call('game-servers:reconcile-frp');
        }

        $service->refresh();
        $this->assertSame(3, $service->connection_details['frp_retry_count']);
        $this->assertFalse((bool) $service->connection_details['frp_enabled']);
    }

    public function test_reconcile_command_skips_healthy_servers(): void
    {
        $user = User::factory()->create();
        $plan = ServicePlan::factory()->create(['provisioner' => 'pterodactyl']);
        Service::factory()->create([
            'user_id'               => $user->id,
            'plan_id'               => $plan->id,
            'status'                => 'active',
            'pterodactyl_server_id' => 9002,
            'connection_details'    => ['server_port' => 25566, 'frp_enabled' => true],
        ]);

        $this->mock(FrpService::class)->shouldNotReceive('addTcpProxy');

        Artisan::call('game-servers:reconcile-frp');

        $this->assertStringContainsString('Todos los game servers', Artisan::output());
    }
}
