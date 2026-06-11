<?php

namespace App\Domains\Platform\Console\Commands;

use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Services\Pterodactyl\GameServerProvisioningService;
use App\Domains\Platform\Support\AdminNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reconciliación de proxies FRP: encuentra game servers activos con servidor
 * de Pterodactyl creado pero SIN proxy FRP (frp_enabled != true) y reintenta
 * SOLO el paso de FRP — nunca re-crea el servidor.
 *
 * Casos que repara:
 *   - Fallos de FRP previos al fix de aprovisionamiento resumible (el job
 *     quedó "succeeded" con frp_enabled=false).
 *   - Caídas transitorias del relay FRP.
 *
 * Tras 3 intentos fallidos consecutivos notifica al admin (una sola vez por
 * racha, para no spamear cada hora).
 */
class ReconcileFrpProxies extends Command
{
    private const NOTIFY_AT_ATTEMPTS = 3;

    protected $signature = 'game-servers:reconcile-frp {--dry-run : Mostrar qué se haría sin aplicar cambios}';

    protected $description = 'Reintenta la creación de proxies FRP en game servers activos que no lo tienen.';

    public function handle(GameServerProvisioningService $gameServers): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $candidates = Service::query()
            ->whereNotNull('pterodactyl_server_id')
            ->whereIn('status', ['active', 'failed'])
            ->get()
            ->filter(function (Service $service) {
                $details = $service->connection_details ?? [];

                // Sin connection_details no hay puerto que reconciliar.
                if (empty($details)) {
                    return false;
                }

                return empty($details['frp_enabled']);
            });

        if ($candidates->isEmpty()) {
            $this->info('Todos los game servers activos tienen su proxy FRP.');
            return self::SUCCESS;
        }

        $fixed = 0;
        $failed = 0;

        foreach ($candidates as $service) {
            $this->line(" → Servicio #{$service->id} ({$service->name}): FRP ausente");

            if ($dryRun) {
                continue;
            }

            try {
                $gameServers->ensureFrpProxy($service);

                // Racha de fallos terminada.
                $this->clearRetryCount($service->fresh());

                if ($service->fresh()->status === 'failed') {
                    $service->update(['status' => 'active']);
                }

                $fixed++;
                $this->info("   ✓ FRP creado para #{$service->id}");
            } catch (\Throwable $e) {
                $failed++;
                $attempts = $this->bumpRetryCount($service->fresh());

                Log::warning('reconcile-frp: reintento de FRP falló', [
                    'service_id' => $service->id,
                    'attempts'   => $attempts,
                    'error'      => $e->getMessage(),
                ]);

                // Notificar al admin SOLO al cruzar el umbral (no cada hora).
                if ($attempts === self::NOTIFY_AT_ATTEMPTS) {
                    AdminNotifier::notify(
                        'Proxy FRP no se pudo restablecer',
                        "El game server '{$service->name}' (#{$service->id}) lleva {$attempts} reintentos sin proxy FRP. El servidor puede ser inalcanzable a través del relay. Error: {$e->getMessage()}",
                        'admin_frp_reconcile_failed',
                        ['service_id' => $service->uuid ?? $service->id, 'attempts' => $attempts],
                        ['email' => true, 'subtitle' => 'Acción requerida', 'action_url' => '/admin/services', 'action_text' => 'Revisar servicio'],
                    );
                }
            }
        }

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . "Candidatos: {$candidates->count()} · Reparados: {$fixed} · Fallidos: {$failed}");

        return self::SUCCESS;
    }

    private function bumpRetryCount(Service $service): int
    {
        $details  = $service->connection_details ?? [];
        $attempts = (int) ($details['frp_retry_count'] ?? 0) + 1;

        $service->update([
            'connection_details' => array_merge($details, ['frp_retry_count' => $attempts]),
        ]);

        return $attempts;
    }

    private function clearRetryCount(Service $service): void
    {
        $details = $service->connection_details ?? [];

        if (isset($details['frp_retry_count'])) {
            unset($details['frp_retry_count']);
            $service->update(['connection_details' => $details]);
        }
    }
}
