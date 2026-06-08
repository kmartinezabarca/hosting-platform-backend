<?php

namespace App\Domains\Platform\Console\Commands;

use App\Domains\Platform\Models\ServerNode;
use App\Domains\Platform\Services\Pterodactyl\PterodactylService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sincroniza los nodos del panel de Pterodactyl con la tabla local `server_nodes`.
 *
 * La tabla `server_nodes` es el catálogo local de infraestructura. Al aprovisionar,
 * el admin puede elegir en qué nodo desplegar (o dejar el default de PTERODACTYL_DEFAULT_NODE),
 * y `PterodactylService::autoSelectNode()` se restringe a estos nodos cuando existen.
 *
 * Uso:
 *   php artisan pterodactyl:sync-nodes              → sincroniza todos los nodos
 *   php artisan pterodactyl:sync-nodes --dry-run    → solo muestra qué haría
 *   php artisan pterodactyl:sync-nodes --prune      → marca offline los nodos que ya no existen en Pterodactyl
 */
class SyncPterodactylNodes extends Command
{
    protected $signature = 'pterodactyl:sync-nodes
                            {--dry-run : Lista los nodos sin escribir en la base de datos}
                            {--prune : Marca como offline los nodos locales que ya no existen en Pterodactyl}';

    protected $description = 'Sincroniza los nodos de Pterodactyl con el catálogo local server_nodes';

    public function __construct(private readonly PterodactylService $pterodactyl)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('🖥️  Sincronizando nodos de Pterodactyl...');

        try {
            $nodes     = $this->pterodactyl->listNodes();
            $locations = $this->pterodactyl->listLocationsMap();
        } catch (\Throwable $e) {
            $this->error("No se pudo conectar con Pterodactyl: {$e->getMessage()}");
            Log::error('SyncPterodactylNodes: fallo al obtener nodos', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        if (empty($nodes)) {
            $this->warn('Pterodactyl no devolvió ningún nodo.');

            return self::SUCCESS;
        }

        $dryRun  = (bool) $this->option('dry-run');
        $created = 0;
        $updated = 0;
        $seenIds = [];

        $defaultNode = config('pterodactyl.default_node');

        foreach ($nodes as $node) {
            $attrs    = $node['attributes'] ?? $node;
            $pteroId  = (int) $attrs['id'];
            $seenIds[] = $pteroId;

            $location = $locations[(int) ($attrs['location_id'] ?? 0)] ?? 'default';

            $data = [
                'name'        => $attrs['name'] ?? "node-{$pteroId}",
                'hostname'    => $attrs['fqdn'] ?? '',
                'ip_address'  => $this->resolveIp($attrs['fqdn'] ?? ''),
                'location'    => $location,
                'node_type'   => 'pterodactyl',
                'specifications' => [
                    'memory'              => $attrs['memory']              ?? null,
                    'memory_overallocate' => $attrs['memory_overallocate'] ?? null,
                    'disk'                => $attrs['disk']                ?? null,
                    'disk_overallocate'   => $attrs['disk_overallocate']  ?? null,
                    'scheme'              => $attrs['scheme']              ?? 'https',
                    'daemon_listen'       => $attrs['daemon_listen']       ?? null,
                    'daemon_sftp'         => $attrs['daemon_sftp']         ?? null,
                    'maintenance_mode'    => $attrs['maintenance_mode']    ?? false,
                ],
                // Priority: el nodo default del .env queda como preferido.
                'priority' => ($defaultNode !== null && (int) $defaultNode === $pteroId) ? 100 : 0,
            ];

            $existing = ServerNode::where('pterodactyl_node_id', $pteroId)->first();

            if ($dryRun) {
                $verb = $existing ? 'actualizaría' : 'crearía';
                $this->line("  • Se {$verb}: #{$pteroId} {$data['name']} ({$data['hostname']}) — {$location}");
                continue;
            }

            if ($existing) {
                // No pisamos campos administrados manualmente (status, max_services, priority si ya fue tocado).
                $existing->update([
                    'name'           => $data['name'],
                    'hostname'       => $data['hostname'],
                    'ip_address'     => $data['ip_address'] ?: $existing->ip_address,
                    'location'       => $data['location'],
                    'node_type'      => 'pterodactyl',
                    'specifications' => $data['specifications'],
                ]);
                $updated++;
                $this->line("  ✏  Actualizado: #{$pteroId} {$data['name']}");
            } else {
                ServerNode::create(array_merge($data, [
                    'uuid'                => (string) Str::uuid(),
                    'pterodactyl_node_id' => $pteroId,
                    'api_credentials'     => [], // las credenciales viven en config/pterodactyl (token global)
                    'status'              => 'active',
                    'max_services'        => 0,   // 0 = sin límite; se ajusta manualmente en admin
                    'current_services'    => 0,
                ]));
                $created++;
                $this->line("  ✅ Creado: #{$pteroId} {$data['name']}");
            }
        }

        if ($this->option('prune') && ! $dryRun && ! empty($seenIds)) {
            $pruned = ServerNode::pterodactyl()
                ->whereNotIn('pterodactyl_node_id', $seenIds)
                ->whereNotNull('pterodactyl_node_id')
                ->update(['status' => 'offline']);

            if ($pruned > 0) {
                $this->warn("  🚫 {$pruned} nodo(s) marcados offline (ya no existen en Pterodactyl)");
            }
        }

        $this->newLine();

        if ($dryRun) {
            $this->info('🔍 Dry-run: no se escribió nada en la base de datos.');

            return self::SUCCESS;
        }

        $this->info("✅ Sincronización completada — {$created} creados, {$updated} actualizados");

        Log::info('SyncPterodactylNodes completado', [
            'created' => $created,
            'updated' => $updated,
        ]);

        return self::SUCCESS;
    }

    /**
     * Intenta resolver una IP a partir del FQDN. Si el FQDN ya es una IP, la devuelve;
     * si no resuelve, devuelve cadena vacía (se conserva la IP previa en updates).
     */
    private function resolveIp(string $fqdn): string
    {
        if ($fqdn === '') {
            return '';
        }

        if (filter_var($fqdn, FILTER_VALIDATE_IP)) {
            return $fqdn;
        }

        $ip = @gethostbyname($fqdn);

        return ($ip !== $fqdn && filter_var($ip, FILTER_VALIDATE_IP)) ? $ip : '';
    }
}
