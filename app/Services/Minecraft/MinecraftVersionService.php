<?php

namespace App\Services\Minecraft;

use App\Models\GameSoftwareVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Opciones de software y versiones para el selector del panel de cliente.
 *
 * Lee exclusivamente desde la tabla `game_software_versions` (curada internamente),
 * eliminando la dependencia de APIs externas (PaperMC, Mojang, Fabric, Forge…).
 *
 * El catálogo de software disponible se define en config/minecraft.php ('software').
 * Las versiones de cada software se obtienen de la BD filtradas por is_active=true.
 */
class MinecraftVersionService
{
    public function options(): array
    {
        return Cache::remember('minecraft:software-options', $this->cacheTtl(), function () {
            return collect(config('minecraft.software', []))
                ->map(function (array $software, string $id) {
                    return [
                        'id'          => $id,
                        'name'        => $software['name'],
                        'description' => $software['description'],
                        'recommended' => (bool) ($software['recommended'] ?? false),
                        'versions'    => $this->versionsFor($software['provider'] ?? $id),
                    ];
                })
                ->filter(fn (array $software) => !empty($software['versions']))
                ->values()
                ->all();
        });
    }

    public function isSupported(string $software, string $version): bool
    {
        foreach ($this->options() as $option) {
            if ($option['id'] === $software && in_array($version, $option['versions'], true)) {
                return true;
            }
        }

        // 'latest' siempre es válida para softwares que la acepten
        if ($version === 'latest') {
            return collect($this->options())->contains('id', $software);
        }

        return false;
    }

    public function latestVersion(string $software = 'paper'): ?string
    {
        $option = collect($this->options())->firstWhere('id', $software);

        return $option['versions'][0]
            ?? config("minecraft.fallback_versions.{$software}.0")
            ?? config('minecraft.defaults.version');
    }

    /** Invalida la caché de opciones (llamar tras modificar versiones en BD). */
    public function invalidateCache(): void
    {
        Cache::forget('minecraft:software-options');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function versionsFor(string $provider): array
    {
        try {
            $versions = GameSoftwareVersion::activeVersionsFor($provider);

            return !empty($versions)
                ? $versions
                : config("minecraft.fallback_versions.{$provider}", []);
        } catch (\Throwable $e) {
            Log::warning("MinecraftVersionService: error al leer BD para '{$provider}'.", [
                'error' => $e->getMessage(),
            ]);

            return config("minecraft.fallback_versions.{$provider}", []);
        }
    }

    private function cacheTtl(): int
    {
        return (int) config('minecraft.versions_cache_ttl', 3600);
    }
}
