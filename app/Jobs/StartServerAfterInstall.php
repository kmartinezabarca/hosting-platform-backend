<?php

namespace App\Jobs;

use App\Models\Service;
use App\Services\Pterodactyl\PterodactylService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class StartServerAfterInstall implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Reintentar el job si el servidor sigue instalando
    public $tries = 20;
    public $backoff = 15; // Esperar 15 segundos entre cada intento

    public function __construct(protected Service $service) {}

    public function handle(PterodactylService $pterodactyl)
    {
        $identifier = $this->service->connection_details['identifier'] ?? null;

        if (!$identifier) return;

        // 1. Consultar recursos (Client API) para ver el estado real
        $resources = $pterodactyl->getServerResources($identifier);
        $state = $resources['current_state'] ?? 'offline';

        Log::info("Job StartServerAfterInstall: Verificando estado", [
            'service' => $this->service->id,
            'state'   => $state,
        ]);

        // 2. Si sigue instalando, devolver el job a la cola para reintentar después
        if ($state === 'installing') {
            $this->release(15); // Volver a intentar en 15 segundos
            return;
        }

        // 3. Si ya terminó (está offline), enviamos señal de START
        if ($state === 'offline') {
            $pterodactyl->sendPowerSignal($identifier, 'start');
            Log::info("Job StartServerAfterInstall: Servidor encendido automáticamente, encolando verificación de Java", [
                'service' => $this->service->id,
            ]);

            // 4. Encolar verificación de compatibilidad de Java con 60 s de delay.
            //    Si el servidor arranca bien, el job termina sin hacer nada.
            //    Si crashea por incompatibilidad de Java, el job detecta y corrige automáticamente.
            CheckAndFixJavaCompatibilityJob::dispatch($this->service)
                ->delay(now()->addSeconds(60));
        }
    }
}
