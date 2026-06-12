<?php

namespace App\Domains\Platform\Compute\Games;

/**
 * Catálogo de presets de servidores de juego (mes 3 — "más juegos"). Las specs
 * (puerto, RAM, jugadores) son datos reales del juego y viven en
 * config('compute.game_presets'); el egg/nest de Pterodactyl viene de env. Un
 * preset sin egg configurado NO es aprovisionable todavía (available=false), de
 * modo que el wizard puede mostrarlo como "próximamente" sin inventar nada.
 *
 * El provisionamiento real (ProvisionGameServerFlow) se conecta después; este
 * catálogo es la fuente de verdad del selector. Determinista, sin infra.
 */
class GamePresetCatalog
{
    /**
     * Todos los presets normalizados.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return array_map(
            fn (string $slug) => $this->forSlug($slug),
            array_keys((array) config('compute.game_presets', [])),
        );
    }

    /** Slugs disponibles en el catálogo. @return string[] */
    public function slugs(): array
    {
        return array_keys((array) config('compute.game_presets', []));
    }

    /** ¿Existe el preset? */
    public function exists(string $slug): bool
    {
        return config("compute.game_presets.{$slug}") !== null;
    }

    /**
     * Un preset normalizado, o null si no existe.
     *
     * @return array<string, mixed>|null
     */
    public function forSlug(string $slug): ?array
    {
        $preset = config("compute.game_presets.{$slug}");

        if (! is_array($preset)) {
            return null;
        }

        $eggId  = $preset['egg_id'] ?? null;
        $nestId = $preset['nest_id'] ?? null;

        return [
            'slug'                => $slug,
            'name'                => (string) ($preset['name'] ?? $slug),
            'default_port'        => isset($preset['default_port']) ? (int) $preset['default_port'] : null,
            'min_ram_mb'          => isset($preset['min_ram_mb']) ? (int) $preset['min_ram_mb'] : null,
            'recommended_ram_mb'  => isset($preset['recommended_ram_mb']) ? (int) $preset['recommended_ram_mb'] : null,
            'max_players'         => isset($preset['max_players']) ? (int) $preset['max_players'] : null,
            // available = el egg de Pterodactyl está configurado por env. Los ids
            // de proveedor NO se exponen al cliente, solo el booleano.
            'available'           => $eggId !== null && $eggId !== '' && $nestId !== null && $nestId !== '',
        ];
    }
}
