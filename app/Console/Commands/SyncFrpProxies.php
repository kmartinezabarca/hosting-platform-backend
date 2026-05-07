<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Services\FrpService;
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
        $services = Service::where('status', 'active')->get();

        $proxies = [];

        foreach ($services as $service) {

            $conn = $service->connection_details ?? [];

            if (!isset($conn['server_port'])) {
                continue;
            }

            $port = (int) $conn['server_port'];

            $proxies[] = [
                'name'       => "mc-{$port}",
                'localPort'  => $port,
                'remotePort' => $port,
            ];
        }

        if ($this->option('dry-run')) {
            $this->info(json_encode($proxies, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $ok = $this->frp->sync($proxies);

        if ($ok) {
            $this->info('FRP synced successfully');
            return self::SUCCESS;
        }

        $this->error('FRP sync failed');
        return self::FAILURE;
    }
}
