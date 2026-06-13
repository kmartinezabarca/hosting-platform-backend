<?php

namespace App\Domains\Platform\Console\Commands;

use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Services\Coolify\CoolifyHealthCheckPayload;
use App\Domains\Platform\Services\Coolify\CoolifyService;
use Illuminate\Console\Command;

class SyncCoolifyHealthChecks extends Command
{
    protected $signature = 'coolify:sync-health-checks
        {--service= : UUID o ID de un servicio especifico}
        {--path= : Path HTTP del health check, por defecto config/coolify.php}
        {--port= : Puerto interno del health check, por defecto el puerto expuesto}
        {--dry-run : Muestra que apps se actualizarian sin llamar a Coolify}';

    protected $description = 'Configura health checks en aplicaciones Coolify existentes de servicios hosting.';

    public function __construct(private readonly CoolifyService $coolify)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $overrides = array_filter([
            'path' => $this->option('path'),
            'port' => $this->option('port'),
        ], fn ($value) => $value !== null && $value !== '');

        $payload = CoolifyHealthCheckPayload::forPort('80', $overrides);
        $dryRun = (bool) $this->option('dry-run');

        $updated = 0;
        $skipped = 0;
        $failed = 0;

        $query = Service::query()
            ->with('plan')
            ->whereHas('plan', fn ($plan) => $plan->where('provisioner', 'coolify'))
            ->whereIn('status', ['active', 'pending', 'suspended', 'failed']);

        $target = $this->option('service');
        if ($target) {
            $query->where(fn ($service) => $service
                ->where('uuid', $target)
                ->orWhere('id', $target));
        }

        $query->chunkById(100, function ($services) use ($payload, $dryRun, &$updated, &$skipped, &$failed) {
            foreach ($services as $service) {
                $appUuid = $this->appUuid($service);

                if (! $appUuid) {
                    $skipped++;
                    $this->line("skip service #{$service->id}: sin coolify_app_uuid");

                    continue;
                }

                if ($dryRun) {
                    $updated++;
                    $this->line("dry-run service #{$service->id} -> app {$appUuid}");

                    continue;
                }

                try {
                    $this->coolify->updateApplication($appUuid, $payload);
                    $updated++;
                    $this->info("updated service #{$service->id} -> app {$appUuid}");
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("failed service #{$service->id} -> app {$appUuid}: {$e->getMessage()}");
                }
            }
        });

        $this->line("done updated={$updated} skipped={$skipped} failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function appUuid(Service $service): ?string
    {
        return $service->connection_details['coolify_app_uuid'] ?? $service->external_id;
    }
}
