<?php

namespace App\Console\Commands;

use App\Models\PterodactylEgg;
use App\Services\Pterodactyl\PterodactylService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza todos los nests y eggs disponibles en el panel de Pterodactyl
 * con la tabla local `pterodactyl_eggs`.
 *
 * Uso:
 *   php artisan pterodactyl:sync-eggs           → sincroniza todos los nests
 *   php artisan pterodactyl:sync-eggs --nest=1  → solo el nest indicado
 *   php artisan pterodactyl:sync-eggs --disable-missing → desactiva eggs que ya no existen en Pterodactyl
 *
 * El scheduler lo ejecuta cada hora automáticamente.
 */
class SyncPterodactylEggs extends Command
{
    protected $signature = 'pterodactyl:sync-eggs
                            {--nest= : ID del nest a sincronizar (omitir = todos)}
                            {--disable-missing : Desactiva eggs que ya no existen en Pterodactyl}';

    protected $description = 'Sincroniza los nests y eggs de Pterodactyl con el catálogo local de juegos';

    public function __construct(private readonly PterodactylService $pterodactyl)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('🎮 Sincronizando eggs de Pterodactyl...');

        $nestId = $this->option('nest') ? (int) $this->option('nest') : null;

        try {
            $nests = $this->fetchNests($nestId);
        } catch (\Throwable $e) {
            $this->error("No se pudo conectar con Pterodactyl: {$e->getMessage()}");
            Log::error('SyncPterodactylEggs: fallo al obtener nests', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        $synced  = 0;
        $created = 0;
        $updated = 0;
        $seenIds = [];

        foreach ($nests as $nest) {
            $nestAttrs = $nest['attributes'] ?? $nest;
            $nestId_   = $nestAttrs['id'];
            $nestName  = $nestAttrs['name']        ?? 'Unknown';
            $nestIdent = $nestAttrs['identifier']  ?? null;
            $nestDesc  = $nestAttrs['description'] ?? null;

            $this->line("  📦 Nest #{$nestId_}: {$nestName}");

            try {
                $eggs = $this->pterodactyl->listNestEggs($nestId_);
            } catch (\Throwable $e) {
                $this->warn("     ⚠ No se pudieron obtener eggs del nest #{$nestId_}: {$e->getMessage()}");
                continue;
            }

            foreach ($eggs as $eggAttrs) {
                $pteroEggId = $eggAttrs['id'];
                $seenIds[]  = "{$nestId_}:{$pteroEggId}";

                $variables  = collect($eggAttrs['relationships']['variables']['data'] ?? [])
                    ->map(fn($v) => $v['attributes'] ?? $v)
                    ->values()
                    ->all();

                $data = [
                    'nest_name'        => $nestName,
                    'nest_identifier'  => $nestIdent,
                    'nest_description' => $nestDesc,
                    'egg_name'         => $eggAttrs['name']        ?? 'Unknown',
                    'egg_description'  => $eggAttrs['description'] ?? null,
                    'egg_author'       => $eggAttrs['author']       ?? null,
                    'docker_image'     => $eggAttrs['docker_image'] ?? '',
                    'startup'          => $eggAttrs['startup']      ?? '',
                    'variables'        => $variables,
                    'config_files'     => $eggAttrs['config']       ?? null,
                    'synced_at'        => now(),
                ];

                $existing = PterodactylEgg::where('ptero_nest_id', $nestId_)
                    ->where('ptero_egg_id', $pteroEggId)
                    ->first();

                if ($existing) {
                    $existing->update($data);
                    $updated++;
                    $this->line("     ✏  Updated: {$eggAttrs['name']}");
                } else {
                    PterodactylEgg::create(array_merge($data, [
                        'ptero_nest_id' => $nestId_,
                        'ptero_egg_id'  => $pteroEggId,
                        'is_active'     => true,
                    ]));
                    $created++;
                    $this->line("     ✅ Created: {$eggAttrs['name']}");
                }

                $synced++;
            }
        }

        // Desactivar eggs que ya no existen en Pterodactyl
        if ($this->option('disable-missing') && ! empty($seenIds)) {
            $all = PterodactylEgg::all();
            $disabled = 0;
            foreach ($all as $egg) {
                $key = "{$egg->ptero_nest_id}:{$egg->ptero_egg_id}";
                if (! in_array($key, $seenIds, true)) {
                    $egg->update(['is_active' => false]);
                    $disabled++;
                    $this->warn("     🚫 Disabled (not in Pterodactyl): {$egg->egg_name}");
                }
            }
            $this->info("Desactivados: {$disabled}");
        }

        $this->newLine();
        $this->info("✅ Sincronización completada — {$synced} eggs procesados ({$created} nuevos, {$updated} actualizados)");

        Log::info('SyncPterodactylEggs completado', [
            'synced'  => $synced,
            'created' => $created,
            'updated' => $updated,
        ]);

        return self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function fetchNests(?int $nestId): array
    {
        $baseUrl = rtrim(config('pterodactyl.base_url'), '/');
        $apiKey  = config('pterodactyl.api_key');

        if ($nestId) {
            $response = Http::baseUrl($baseUrl)
                ->withToken($apiKey)
                ->withoutVerifying()
                ->acceptJson()
                ->get("/api/application/nests/{$nestId}");

            if ($response->failed()) {
                throw new \RuntimeException("Nest #{$nestId} no encontrado: HTTP {$response->status()}");
            }

            return [['attributes' => $response->json('attributes')]];
        }

        $response = Http::baseUrl($baseUrl)
            ->withToken($apiKey)
            ->withoutVerifying()
            ->acceptJson()
            ->get('/api/application/nests', ['per_page' => 100]);

        if ($response->failed()) {
            throw new \RuntimeException("No se pudo obtener la lista de nests: HTTP {$response->status()}");
        }

        return $response->json('data', []);
    }
}
