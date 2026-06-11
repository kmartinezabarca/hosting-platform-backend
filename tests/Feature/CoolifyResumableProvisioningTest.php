<?php

namespace Tests\Feature;

use App\Domains\Platform\Models\ProvisioningJob;
use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Models\ServicePlan;
use App\Domains\Platform\Services\CloudflareService;
use App\Domains\Platform\Services\Coolify\CoolifyService;
use App\Domains\Platform\Services\Coolify\HostingProvisioningService;
use App\Domains\Platform\Services\ProvisioningService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Aprovisionamiento Coolify resumible (fix de proyectos huérfanos):
 * cada paso persiste su marcador de inmediato y el reintento retoma desde el
 * último paso completado sin crear recursos duplicados.
 */
class CoolifyResumableProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['coolify.server_uuid' => 'srv-test', 'coolify.hosting_dns_ip' => null]);
    }

    /** @return array{0: User, 1: Service} */
    private function makeCoolifyService(): array
    {
        $user = User::factory()->create();
        $plan = ServicePlan::factory()->create([
            'provisioner'        => 'coolify',
            'provisioner_config' => ['build_pack' => 'static', 'db_enabled' => false],
        ]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status'  => 'pending',
            'name'    => 'Sitio Test',
        ]);

        return [$user, $service];
    }

    public function test_failure_after_create_project_persists_uuid_and_retry_does_not_duplicate_project(): void
    {
        [, $service] = $this->makeCoolifyService();

        // El mock de Cloudflare no debe usarse (hosting_dns_ip = null → DNS omitido).
        $this->mock(CloudflareService::class)->shouldIgnoreMissing();

        // Intento 1: el proyecto se crea, la app falla.
        $coolify = $this->mock(CoolifyService::class);
        $coolify->shouldReceive('createProject')
            ->once()
            ->andReturn(['uuid' => 'proj-123']);
        $coolify->shouldReceive('createApplication')
            ->once()
            ->andThrow(new \RuntimeException('Coolify 500'));

        try {
            app(HostingProvisioningService::class)->provision($service->fresh(['plan', 'user']));
            $this->fail('Se esperaba la excepción del primer intento.');
        } catch (\RuntimeException) {
            // esperado
        }

        $service->refresh();

        // El UUID del proyecto quedó persistido ANTES del fallo (fix de huérfanos)
        // y el servicio NO está marcado como aprovisionado.
        $this->assertSame('proj-123', $service->connection_details['coolify_project_uuid'] ?? null);
        $this->assertArrayNotHasKey('coolify_provisioned_at', $service->connection_details ?? []);
        $this->assertSame('failed', $service->status);

        // Intento 2: NO se crea un segundo proyecto; se reanuda con el UUID guardado.
        $coolify = $this->mock(CoolifyService::class);
        $coolify->shouldNotReceive('createProject');
        $coolify->shouldReceive('createApplication')
            ->once()
            ->withArgs(fn (array $payload) => $payload['project_uuid'] === 'proj-123')
            ->andReturn(['uuid' => 'app-456']);

        app(HostingProvisioningService::class)->provision($service->fresh(['plan', 'user']));

        $service->refresh();
        $this->assertSame('active', $service->status);
        $this->assertSame('proj-123', $service->connection_details['coolify_project_uuid']);
        $this->assertSame('app-456', $service->connection_details['coolify_app_uuid']);
        $this->assertNotEmpty($service->connection_details['coolify_provisioned_at']);
    }

    public function test_partially_provisioned_service_is_not_marked_already_provisioned(): void
    {
        [, $service] = $this->makeCoolifyService();

        // Estado parcial: app creada pero sin bandera de finalización (DB/DNS pendientes).
        $service->update([
            'status'             => 'failed',
            'connection_details' => [
                'coolify_project_uuid' => 'proj-p',
                'coolify_app_uuid'     => 'app-p',
            ],
        ]);

        $job = ProvisioningJob::create([
            'service_id'   => $service->id,
            'provider'     => ProvisioningJob::PROVIDER_COOLIFY,
            'status'       => ProvisioningJob::STATUS_PENDING,
            'available_at' => now(),
        ]);

        $this->mock(CloudflareService::class)->shouldIgnoreMissing();

        // runJob debe RE-ENTRAR a provision() (no atajo de idempotencia) y
        // completar los pasos faltantes sin recrear proyecto ni app.
        $coolify = $this->mock(CoolifyService::class);
        $coolify->shouldNotReceive('createProject');
        $coolify->shouldNotReceive('createApplication');

        $ok = app(ProvisioningService::class)->runJob($job);

        $this->assertTrue($ok);
        $service->refresh();
        $this->assertSame('active', $service->status);
        $this->assertNotEmpty($service->connection_details['coolify_provisioned_at']);
    }

    public function test_terminate_cleans_only_known_resources(): void
    {
        [, $service] = $this->makeCoolifyService();

        // Solo existe el proyecto (la app nunca llegó a crearse).
        $service->update([
            'connection_details' => ['coolify_project_uuid' => 'proj-orphan'],
        ]);

        $coolify = $this->mock(CoolifyService::class);
        $coolify->shouldNotReceive('deleteApplication');
        $coolify->shouldNotReceive('deleteDatabase');
        $coolify->shouldReceive('deleteProject')->once()->with('proj-orphan');

        $this->mock(CloudflareService::class)->shouldIgnoreMissing();

        app(HostingProvisioningService::class)->terminate($service->fresh(['plan', 'user']));

        $service->refresh();
        $this->assertSame('terminated', $service->status);
    }
}
