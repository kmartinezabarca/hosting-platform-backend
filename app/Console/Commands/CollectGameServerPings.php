<?php

namespace App\Console\Commands;

use App\Models\GameServerPing;
use App\Models\Service;
use App\Services\Minecraft\MinecraftPingService;
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

        $query = Service::query()
            ->where('status', 'active')
            ->whereNotNull('connection_details')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(connection_details, '$.host')) IS NOT NULL");

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
            $host    = $details['host'] ?? null;
            $port    = (int) ($details['port'] ?? 25565);

            if (!$host) continue;

            try {
                $result   = $pinger->ping($host, $port, timeoutMs: 3000);
                $isOnline = $result !== null;
                $pingMs   = $result['ping_ms']             ?? null;
                $players  = $result['players']['online']   ?? null;
            } catch (\Throwable $e) {
                Log::warning('collect-pings: error al pingear servicio', [
                    'service_id' => $service->id,
                    'host'       => $host,
                    'error'      => $e->getMessage(),
                ]);
                $isOnline = false;
                $pingMs   = null;
                $players  = null;
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
            }

            $sampled++;
        }

        $this->info("collect-pings: {$sampled} servicio(s) muestreados." . ($dryRun ? ' [dry-run]' : ''));

        return self::SUCCESS;
    }
}
