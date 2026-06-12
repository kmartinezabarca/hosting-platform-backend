<?php

namespace App\Domains\Platform\Console\Commands;

use App\Domains\Platform\Compute\Services\ComputeMirror;
use App\Domains\Platform\Models\Service;
use Illuminate\Console\Command;

/**
 * Backfill/reconciliación de game servers al plano de cómputo. Idempotente —
 * seguro de correr en cron o a mano.
 *
 *   php artisan platform:compute:mirror-game-servers
 */
class MirrorGameServersToCompute extends Command
{
    protected $signature = 'platform:compute:mirror-game-servers';

    protected $description = 'Espeja los game servers (Services con Pterodactyl) como Resources del plano de cómputo';

    public function handle(ComputeMirror $mirror): int
    {
        $synced = 0;

        Service::query()
            ->whereNotNull('pterodactyl_server_id')
            ->whereNotIn('status', ['terminated'])
            ->with(['user', 'selectedEgg'])
            ->chunkById(100, function ($services) use ($mirror, &$synced) {
                foreach ($services as $service) {
                    if ($mirror->syncGameServer($service)) {
                        $synced++;
                    }
                }
            });

        $this->info("Game servers espejados al plano de cómputo: {$synced}.");

        return self::SUCCESS;
    }
}
