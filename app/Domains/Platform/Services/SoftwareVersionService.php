<?php

namespace App\Domains\Platform\Services;

use App\Domains\Platform\Models\GameSoftwareVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Devuelve las versiones de software de servidor disponibles para los clientes.
 *
 * Las versiones ya NO se obtienen de APIs externas (PaperMC, Mojang, Fabric…).
 * Se leen directamente de la tabla `game_software_versions`, curada por el equipo,
 * garantizando compatibilidad real con nuestros eggs de Pterodactyl.
 *
 * Caché: configurable vía MINECRAFT_VERSIONS_CACHE_TTL (default 1 h).
 * Se invalida automáticamente cuando se agrega/edita una versión con:
 *   php artisan game:versions {add|enable|disable|…}
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  Identificador     │ Descripción                                         │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │  paper             │ Paper MC (Bukkit/Spigot compatible)                 │
 * │  velocity          │ Velocity proxy                                      │
 * │  folia             │ Folia (hilos regionales)                            │
 * │  purpur            │ Purpur (fork de Paper)                              │
 * │  purpur-geyser     │ Purpur + Geyser (acceso Bedrock)                    │
 * │  vanilla           │ Servidor oficial de Mojang                          │
 * │  bedrock           │ Servidor Java con puente Geyser                     │
 * │  fabric            │ Fabric Loader                                       │
 * │  quilt             │ Quilt (fork de Fabric)                              │
 * │  forge             │ MinecraftForge                                      │
 * │  neoforge          │ NeoForge (desde MC 1.20.2)                         │
 * │  arclight          │ Arclight (Forge + Paper híbrido)                    │
 * │  sponge            │ SpongeVanilla                                       │
 * │  bungeecord        │ BungeeCord proxy                                    │
 * │  spigot            │ Spigot (BuildTools)                                 │
 * │  nukkit            │ Nukkit (Bedrock Edition nativo)                     │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
class SoftwareVersionService
{
    // La caché evita consultas repetidas a la BD en cada request
    private const CACHE_TTL_SECONDS = 3600; // 1 h (la BD cambia raramente)

    // ─────────────────────────────────────────────────────────────────────────
    // API pública
    // ─────────────────────────────────────────────────────────────────────────

    public static function cacheKey(string $identifier): string
    {
        return 'software_versions_' . strtolower($identifier);
    }

    /**
     * Devuelve versiones desde caché o desde la BD.
     *
     * @return array{identifier:string, versions:string[], cached:bool, source:string}
     */
    public function getVersions(string $identifier): array
    {
        $id  = strtolower(trim($identifier));
        $key = self::cacheKey($id);
        $ttl = (int) config('minecraft.versions_cache_ttl', self::CACHE_TTL_SECONDS);

        $cached   = Cache::has($key);
        $versions = Cache::remember($key, $ttl, fn () => $this->queryDatabase($id));

        return [
            'identifier' => $id,
            'versions'   => $versions,
            'cached'     => $cached,
            'source'     => 'database',
        ];
    }

    /**
     * Fuerza la recarga desde BD e invalida la caché para un identificador.
     * Usado por `php artisan software:refresh-versions` y `php artisan game:versions`.
     *
     * @return array{identifier:string, versions:string[], cached:bool, source:string}
     */
    public function refreshVersions(string $identifier): array
    {
        $id  = strtolower(trim($identifier));
        $key = self::cacheKey($id);
        $ttl = (int) config('minecraft.versions_cache_ttl', self::CACHE_TTL_SECONDS);

        Cache::forget($key);
        $versions = $this->queryDatabase($id);
        Cache::put($key, $versions, $ttl);

        return [
            'identifier' => $id,
            'versions'   => $versions,
            'cached'     => false,
            'source'     => 'database',
        ];
    }

    /**
     * Invalida la caché para un software específico.
     * Llamado internamente cuando se agrega/edita/desactiva una versión.
     */
    public function invalidateCache(string $identifier): void
    {
        Cache::forget(self::cacheKey(strtolower($identifier)));
    }

    /** Lista de todos los identificadores conocidos. */
    public static function knownIdentifiers(): array
    {
        return [
            'paper', 'velocity', 'folia',
            'purpur', 'purpur-geyser',
            'vanilla', 'bedrock',
            'fabric', 'quilt',
            'forge', 'neoforge', 'arclight', 'sponge',
            'bungeecord', 'spigot',
            'nukkit',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Consulta interna a la BD
    // ─────────────────────────────────────────────────────────────────────────

    private function queryDatabase(string $identifier): array
    {
        try {
            $versions = GameSoftwareVersion::activeVersionsFor($identifier);

            if (!empty($versions)) {
                return $versions;
            }

            // Si no hay versiones en BD (software aún no sembrado), log + fallback
            Log::warning("SoftwareVersionService: sin versiones en BD para '{$identifier}'.", [
                'fallback' => config("minecraft.fallback_versions.{$identifier}", []),
            ]);

            return config("minecraft.fallback_versions.{$identifier}", ['latest']);
        } catch (\Throwable $e) {
            Log::error("SoftwareVersionService: error consultando BD para '{$identifier}'.", [
                'error' => $e->getMessage(),
            ]);

            return config("minecraft.fallback_versions.{$identifier}", ['latest']);
        }
    }
}
