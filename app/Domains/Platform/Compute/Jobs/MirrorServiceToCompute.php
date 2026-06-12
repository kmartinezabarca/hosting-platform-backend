<?php

namespace App\Domains\Platform\Compute\Jobs;

use App\Domains\Platform\Compute\Services\ComputeMirror;
use App\Domains\Platform\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Espejo asíncrono billing → cómputo tras un aprovisionamiento exitoso.
 * No-fatal por diseño: si falla, el comando platform:compute:mirror-game-servers
 * lo reconcilia después.
 */
class MirrorServiceToCompute implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $serviceId)
    {
    }

    public function handle(ComputeMirror $mirror): void
    {
        $service = Service::with(['user', 'selectedEgg'])->find($this->serviceId);

        if ($service) {
            $mirror->syncGameServer($service);
        }
    }
}
