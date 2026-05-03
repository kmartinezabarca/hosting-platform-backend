<?php

namespace App\Services\Minecraft;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MinecraftVersionService
{
    public function options(): array
    {
        return Cache::remember('minecraft:software-options', $this->cacheTtl(), function () {
            return collect(config('minecraft.software', []))
                ->map(function (array $software, string $id) {
                    return [
                        'id' => $id,
                        'name' => $software['name'],
                        'description' => $software['description'],
                        'recommended' => (bool) ($software['recommended'] ?? false),
                        'versions' => $this->versionsFor($software['provider'] ?? $id),
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

        return false;
    }

    public function latestVersion(string $software = 'paper'): ?string
    {
        $option = collect($this->options())->firstWhere('id', $software);

        return $option['versions'][0]
            ?? config("minecraft.fallback_versions.{$software}.0")
            ?? config('minecraft.defaults.version');
    }

    private function versionsFor(string $provider): array
    {
        try {
            $versions = match ($provider) {
                'paper' => $this->paperVersions(),
                'purpur' => $this->purpurVersions(),
                'fabric' => $this->fabricVersions(),
                'forge' => $this->forgeVersions(),
                'vanilla' => $this->vanillaVersions(),
                default => [],
            };

            return !empty($versions)
                ? $versions
                : config("minecraft.fallback_versions.{$provider}", []);
        } catch (\Throwable $e) {
            Log::warning("No se pudieron obtener versiones de Minecraft para {$provider}", [
                'error' => $e->getMessage(),
            ]);

            return config("minecraft.fallback_versions.{$provider}", []);
        }
    }

    private function paperVersions(): array
    {
        $response = Http::timeout(10)->acceptJson()->get('https://fill.papermc.io/v3/projects/paper');

        if ($response->successful()) {
            $versions = $response->json('versions', []);

            if (is_array($versions) && array_is_list($versions)) {
                return $this->stableReleaseVersions($versions);
            }

            if (is_array($versions)) {
                return $this->stableReleaseVersions(collect($versions)->flatten()->all());
            }
        }

        $fallback = Http::timeout(10)->acceptJson()->get('https://api.papermc.io/v2/projects/paper');

        return $this->stableReleaseVersions($fallback->json('versions', []));
    }

    private function purpurVersions(): array
    {
        $response = Http::timeout(10)->acceptJson()->get('https://api.purpurmc.org/v2/purpur/');

        return $this->stableReleaseVersions($response->json('versions', []));
    }

    private function fabricVersions(): array
    {
        $response = Http::timeout(10)->acceptJson()->get('https://meta.fabricmc.net/v2/versions/game');

        return $this->stableReleaseVersions(
            collect($response->json())
                ->filter(fn (array $version) => $version['stable'] ?? false)
                ->pluck('version')
                ->all()
        );
    }

    private function forgeVersions(): array
    {
        $response = Http::timeout(10)->acceptJson()
            ->get('https://files.minecraftforge.net/net/minecraftforge/forge/promotions_slim.json');

        $promos = $response->json('promos', []);

        return $this->stableReleaseVersions(
            collect(array_keys($promos))
                ->map(fn (string $key) => preg_replace('/-(latest|recommended)$/', '', $key))
                ->unique()
                ->all()
        );
    }

    private function vanillaVersions(): array
    {
        $response = Http::timeout(10)->acceptJson()
            ->get('https://piston-meta.mojang.com/mc/game/version_manifest_v2.json');

        return $this->stableReleaseVersions(
            collect($response->json('versions', []))
                ->filter(fn (array $version) => ($version['type'] ?? null) === 'release')
                ->pluck('id')
                ->all()
        );
    }

    private function stableReleaseVersions(array $versions): array
    {
        return collect($versions)
            ->filter(fn ($version) => is_string($version) && preg_match('/^\d+\.\d+(\.\d+)?$/', $version))
            ->unique()
            ->sort(fn (string $a, string $b) => version_compare($b, $a))
            ->take(20)
            ->values()
            ->all();
    }

    private function cacheTtl(): int
    {
        return (int) config('minecraft.versions_cache_ttl', 3600);
    }
}
