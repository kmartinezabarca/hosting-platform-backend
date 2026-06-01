<?php

namespace App\Domains\Platform\Console\Commands;

use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Services\FrpService;
use Illuminate\Console\Command;

class SyncFrpProxies extends Command
{
    protected $signature = 'frp:sync {--dry-run}';
    protected $description = 'Sync FRP proxies (production SaaS mode)';

    public function __construct(private FrpService $frp)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->warn('--- INICIANDO SINCRONIZACIÓN FRP (V5 - SCP) ---');
        
        $services = Service::where('status', 'active')->get();
        $this->info('Servicios activos encontrados: ' . $services->count());

        $proxies = [];

        foreach ($services as $service) {

            $conn = $service->connection_details ?? [];

            if (!isset($conn['server_port'])) {
                continue;
            }

            $port = (int) $conn['server_port'];

            $proxies[] = [
                'name'       => "mc-{$port}",
                'type'       => 'tcp',
                'localPort'  => $port,
                'remotePort' => $port,
            ];
        }

        if ($this->option('dry-run')) {
            $this->info(json_encode($proxies, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info('Enviando ' . count($proxies) . ' proxies al servidor...');
        
        try {
            $ok = $this->frp->sync($proxies);

            if ($ok) {
                $this->info('✅ FRP synced successfully');
                return self::SUCCESS;
            }
        } catch (\Throwable $e) {
            $this->error('❌ Error fatal: ' . $e->getMessage());
        }

        $this->error('❌ FRP sync failed. Revisa storage/logs/laravel.log para más detalles.');
        return self::FAILURE;
    }
}
