<?php

namespace App\Domains\Platform\Console\Commands;

use App\Domains\Platform\Events\GameServerPingBroadcast;
use App\Domains\Platform\Models\GameServerPing;
use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Services\Minecraft\MinecraftPingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Muestrea el ping de todos los game servers activos de Minecraft y
 * persiste los resultados en game_server_pings (usado por el historial de latencia).
 *
 * Se ejecuta cada 5 minutos desde Console\Kernel.
 * También purga registros con más de 48 h de antigüedad para mantener la tabla acotada.
 */
class CollectGameServerPings extends Command
{
    protected $signature = 'game-servers:collect-pings
                            {--dry-run : Mostrar resultados sin persistir}
                            {--service= : Solo muestrear un servicio específico (UUID)}';

    protected $description = 'Muestrea y persiste el ping SLP de todos los game servers activos';

    public function handle(MinecraftPingService $pinger): int
    {
        $dryRun   = $this->option('dry-run');
        $uuidOnly = $this->option('service');

        // Purgar muestras antiguas (>48 h) antes de insertar nuevas
        if (!$dryRun) {
            GameServerPing::where('sampled_at', '<', now()->subHours(48))->delete();
        }

        // NOTA: connection_details está cifrada (encrypted:array cast en Service), por lo que
        // NO se puede usar JSON_EXTRACT a nivel SQL — el campo en BD es ciphertext, no JSON.
        // El filtro fino (presencia de 'host') se hace en PHP después de que Eloquent lo descifre.
        $query = Service::query()
            ->where('status', 'active')
            ->whereNotNull('connection_details');

        if ($uuidOnly) {
            $query->where('uuid', $uuidOnly);
        }

        $services = $query->get(['id', 'uuid', 'connection_details']);

        if ($services->isEmpty()) {
            $this->info('No hay game servers activos con host configurado.');
            return self::SUCCESS;
        }

        $sampled = 0;
        $now     = now();

        foreach ($services as $service) {
            $details = $service->connection_details ?? [];
            // 'server_ip' / 'server_port' son las claves canónicas en connection_details.
            // 'host' / 'port' son alias legacy; se mantiene el fallback por compatibilidad.
            $host = $details['host']       ?? $details['server_ip']   ?? null;
            $port = (int) ($details['port'] ?? $details['server_port'] ?? 25565);

            if (!$host) continue;

            try {
                $result   = $pinger->ping($host, $port, timeoutMs: 3000);
                $isOnline = $result !== null;
                $pingMs   = $result['ping_ms']             ?? null;
                $players  = $result['players']['online']   ?? null;
                $sample   = $result['players']['sample']   ?? [];
            } catch (\Throwable $e) {
                Log::warning('collect-pings: error al pingear servicio', [
                    'service_id' => $service->id,
                    'host'       => $host,
                    'error'      => $e->getMessage(),
                ]);
                $isOnline = false;
                $pingMs   = null;
                $players  = null;
                $sample   = [];
            }

            if ($this->option('verbose')) {
                $status = $isOnline ? "{$pingMs}ms" : 'timeout';
                $this->line("  [{$service->uuid}] {$host}:{$port} → {$status}");
            }

            if (!$dryRun) {
                GameServerPing::create([
                    'service_id' => $service->id,
                    'ping_ms'    => $isOnline ? $pingMs : null,
                    'is_online'  => $isOnline,
                    'players'    => $players,
                    'sampled_at' => $now,
                ]);

                // Emitir en tiempo real vía Reverb → el frontend actualiza el HUD sin polling
                try {
                    GameServerPingBroadcast::dispatch(
                        $service->uuid,
                        $isOnline ? $pingMs : null,
                        $isOnline,
                        $players,
                        is_array($sample) ? $sample : [],
                    );
                } catch (\Throwable $e) {
                    Log::warning('collect-pings: no se pudo broadcast ping', [
                        'service_uuid' => $service->uuid,
                        'error'        => $e->getMessage(),
                    ]);
                }
            }

            $sampled++;
        }

        $this->info("collect-pings: {$sampled} servicio(s) muestreados." . ($dryRun ? ' [dry-run]' : ''));

        return self::SUCCESS;
    }
}
