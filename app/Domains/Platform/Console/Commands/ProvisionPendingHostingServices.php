<?php

namespace App\Domains\Platform\Console\Commands;

use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Services\Coolify\HostingProvisioningService;
use Illuminate\Console\Command;

class ProvisionPendingHostingServices extends Command
{
    protected $signature = 'hosting:provision-pending {uuid? : UUID del servicio a aprovisionar} {--dry-run : Solo listar servicios pendientes}';

    protected $description = 'Aprovisiona servicios Web Hosting (Coolify) que existen localmente pero no han sido aprovisionados.';

    public function handle(HostingProvisioningService $provisioner): int
    {
        $query = Service::query()
            ->with(['plan', 'user'])
            ->whereHas('plan', fn ($plan) => $plan->where('provisioner', 'coolify'))
            ->whereNull('external_id')
            ->where(function ($services) {
                $services
                    ->whereNull('connection_details')
                    ->orWhere('connection_details', '[]')
                    ->orWhere('connection_details', '{}');
            });

        if ($uuid = $this->argument('uuid')) {
            $query->where('uuid', $uuid);
        }

        $services = $query->orderBy('id')->get();

        if ($services->isEmpty()) {
            $this->info('No hay servicios de hosting pendientes de aprovisionar.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['ID', 'UUID', 'Usuario', 'Plan', 'Dominio', 'Estado'],
                $services->map(fn (Service $service) => [
                    $service->id,
                    $service->uuid,
                    $service->user?->email,
                    $service->plan?->name,
                    $service->domain ?: '-',
                    $service->status,
                ])->all()
            );

            return self::SUCCESS;
        }

        if (!$this->hasCoolifyCredentials()) {
            $this->error('Faltan credenciales de Coolify. Configura COOLIFY_API_TOKEN y COOLIFY_SERVER_UUID.');

            return self::FAILURE;
        }

        $failed = 0;

        foreach ($services as $service) {
            try {
                $this->line("Aprovisionando {$service->uuid} ({$service->plan?->name})...");
                $provisioner->provision($service);
                $this->info("OK {$service->uuid}");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("ERROR {$service->uuid}: {$e->getMessage()}");
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function hasCoolifyCredentials(): bool
    {
        return filled(config('coolify.api_token')) && filled(config('coolify.server_uuid'));
    }
}
