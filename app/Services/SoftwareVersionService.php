<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Consulta y cachea las versiones disponibles de distintos softwares
 * de servidor de Minecraft/Bedrock para los Eggs de Pterodactyl.
 *
 * Caché: 24 horas por identificador (key: software_versions_{id}).
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  Identificador     │ Egg ID │ Fuente                                    │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │  paper             │  1     │ PaperMC API                               │
 * │  velocity          │  26    │ PaperMC API                               │
 * │  folia             │  -     │ PaperMC API                               │
 * │  purpur            │  27    │ PurpurMC API                              │
 * │  purpur-geyser     │  25    │ PurpurMC API (alias)                      │
 * │  vanilla           │  3     │ Mojang Launcher Meta (type=release)       │
 * │  bedrock           │  29    │ Mojang Launcher Meta (alias)              │
 * │  fabric            │  15    │ Fabric Meta (stable=true)                 │
 * │  quilt             │  31    │ Quilt Meta (stable=true)                  │
 * │  forge             │  5     │ Maven — minecraftforge                    │
 * │  neoforge          │  34    │ Maven — neoforged                         │
 * │  arclight          │  33    │ Maven — islight.cc                        │
 * │  sponge            │  4     │ Maven — spongepowered                     │
 * │  bungeecord        │  2     │ Spiget API resource 2                     │
 * │  spigot            │  30    │ SpigotMC Hub (regex sobre directorio)     │
 * │  nukkit            │  28    │ Jenkins CI (OpenCollab)                   │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
class SoftwareVersionService
{
    private const CACHE_TTL_SECONDS = 86400; // 24 h

    // ─────────────────────────────────────────────────────────────────────────
    // API pública
    // ─────────────────────────────────────────────────────────────────────────

    public static function cacheKey(string $identifier): string
    {
        return 'software_versions_' . strtolower($identifier);
    }

    /**
     * Devuelve versiones desde caché o las fetcha en tiempo real.
     * Si la API falla NO se cachea el fallback — el próximo request reintenta.
     *
     * @return array{identifier:string, versions:string[], cached:bool, source:string}
     */
    public function getVersions(string $identifier): array
    {
        $id  = strtolower(trim($identifier));
        $key = self::cacheKey($id);

        if (Cache::has($key)) {
            return [
                'identifier' => $id,
                'versions'   => Cache::get($key),
                'cached'     => true,
                'source'     => $this->resolveSource($id),
            ];
        }

        try {
            $versions = $this->fetchVersions($id);
            Cache::put($key, $versions, self::CACHE_TTL_SECONDS);

            return [
                'identifier' => $id,
                'versions'   => $versions,
                'cached'     => false,
                'source'     => $this->resolveSource($id),
            ];
        } catch (\Throwable $e) {
            Log::error("SoftwareVersionService: fallo para '{$id}' — sin cachear.", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'identifier' => $id,
                'versions'   => ['latest'],
                'cached'     => false,
                'source'     => $this->resolveSource($id),
            ];
        }
    }

    /**
     * Fuerza refresh del caché para un identificador.
     * Lanza excepción — útil para el comando artisan que quiere reportar errores.
     *
     * @return array{identifier:string, versions:string[], cached:bool, source:string}
     */
    public function refreshVersions(string $identifier): array
    {
        $id  = strtolower(trim($identifier));
        $key = self::cacheKey($id);

        Cache::forget($key);
        $versions = $this->fetchVersions($id);
        Cache::put($key, $versions, self::CACHE_TTL_SECONDS);

        return [
            'identifier' => $id,
            'versions'   => $versions,
            'cached'     => false,
            'source'     => $this->resolveSource($id),
        ];
    }

    /** Lista de todos los identificadores conocidos (usada por el comando artisan). */
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
    // Dispatcher
    // ─────────────────────────────────────────────────────────────────────────

    private function fetchVersions(string $id): array
    {
        return match (true) {
            // PaperMC
            in_array($id, ['paper', 'velocity', 'folia'], true)  => $this->fetchPaperMC($id),
            // Purpur (purpur-geyser usa las mismas builds)
            in_array($id, ['purpur', 'purpur-geyser'], true)      => $this->fetchPurpur(),
            // Mojang
            in_array($id, ['vanilla', 'bedrock'], true)           => $this->fetchVanilla(),
            // Fabric / Quilt (JSON con campo stable)
            $id === 'fabric'                                       => $this->fetchFabricLike('https://meta.fabricmc.net/v1/versions/game', 'Fabric'),
            $id === 'quilt'                                        => $this->fetchFabricLike('https://meta.quiltmc.org/v3/versions/game', 'Quilt'),
            // Maven XML — un método genérico para todos
            $id === 'forge'     => $this->fetchMaven(
                'https://maven.minecraftforge.net/net/minecraftforge/forge/maven-metadata.xml',
                'Forge'
            ),
            $id === 'neoforge'  => $this->fetchMaven(
                'https://maven.neoforged.net/releases/net/neoforged/neoforge/maven-metadata.xml',
                'NeoForge'
            ),
            $id === 'arclight'  => $this->fetchArclight(),
            $id === 'sponge'    => $this->fetchMaven(
                'https://repo.spongepowered.org/repository/maven-public/org/spongepowered/spongevanilla/maven-metadata.xml',
                'Sponge'
            ),
            // Spiget (BungeeCord = recurso 2, Spigot = recurso 1 no disponible → hub)
            $id === 'bungeecord' => $this->fetchSpiget(2, 'BungeeCord'),
            $id === 'spigot'     => $this->fetchSpigotHub(),
            // Jenkins CI (Nukkit ahora en fetchArclight/fetchNukkit separados)
            $id === 'nukkit'     => $this->fetchNukkit(),
            // Fallback: intentar como proyecto de PaperMC
            default              => $this->fetchPaperMC($id),
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fetchers
    // ─────────────────────────────────────────────────────────────────────────

    /** PaperMC API — paper, velocity, folia. Orden descendente. */
    private function fetchPaperMC(string $project): array
    {
        $res = Http::timeout(15)->withoutVerifying()
            ->get("https://api.papermc.io/v2/projects/{$project}");

        $this->assertOk($res, "PaperMC ({$project})");

        $versions = $res->json('versions', []);
        $this->assertNotEmpty($versions, "PaperMC ({$project})", $res->body());

        return array_values(array_reverse($versions));
    }

    /** PurpurMC API — purpur y purpur-geyser. Orden descendente. */
    private function fetchPurpur(): array
    {
        $res = Http::timeout(15)->withoutVerifying()
            ->get('https://api.purpurmc.org/v2/purpur');

        $this->assertOk($res, 'Purpur');

        $versions = $res->json('versions', []);
        $this->assertNotEmpty($versions, 'Purpur', $res->body());

        return array_values(array_reverse($versions));
    }

    /** Mojang Launcher Meta — vanilla y bedrock. Solo type=release. */
    private function fetchVanilla(): array
    {
        $res = Http::timeout(15)->withoutVerifying()
            ->get('https://launchermeta.mojang.com/mc/game/version_manifest.json');

        $this->assertOk($res, 'Mojang');

        $versions = collect($res->json('versions', []))
            ->where('type', 'release')
            ->pluck('id')
            ->values()
            ->all();

        $this->assertNotEmpty($versions, 'Mojang (vanilla/bedrock)', $res->body());

        return $versions;
    }

    /**
     * Fabric / Quilt — JSON con objetos {version, stable}.
     * Solo versiones estables (stable=true).
     * Orden descendente (la API ya lo devuelve así).
     */
    private function fetchFabricLike(string $url, string $label): array
    {
        $res = Http::timeout(15)->withoutVerifying()->get($url);
        $this->assertOk($res, $label);

        $versions = collect($res->json() ?? [])
            ->where('stable', true)
            ->pluck('version')
            ->values()
            ->all();

        $this->assertNotEmpty($versions, "{$label} (stable)", $res->body());

        return $versions;
    }

    /**
     * Maven metadata XML — forge, neoforge, arclight, sponge.
     *
     * Estructura esperada:
     *   <metadata>
     *     <versioning>
     *       <versions>
     *         <version>…</version>
     *       </versions>
     *     </versioning>
     *   </metadata>
     *
     * Devuelve versiones en orden descendente (más nueva primero).
     */
    private function fetchMaven(string $url, string $label): array
    {
        $res = Http::timeout(15)->withoutVerifying()->get($url);
        $this->assertOk($res, "{$label} Maven");

        $prev = libxml_use_internal_errors(true);
        $xml  = simplexml_load_string($res->body());
        libxml_use_internal_errors($prev);

        if ($xml === false) {
            $errs = implode('; ', array_map(fn($e) => trim($e->message), libxml_get_errors()));
            libxml_clear_errors();
            throw new \RuntimeException("{$label} Maven: XML inválido — {$errs}");
        }

        $versions = [];
        foreach ($xml->versioning->versions->version ?? [] as $v) {
            $str = trim((string) $v);
            if ($str !== '') {
                $versions[] = $str;
            }
        }

        $this->assertNotEmpty($versions, "{$label} Maven XML", substr($res->body(), 0, 400));

        return array_values(array_reverse($versions));
    }

    /**
     * Arclight — Maven primario (maven.islight.cc) con fallback a GitHub Releases.
     *
     * El dominio maven.islight.cc puede estar caído o bloqueado en algunos entornos.
     * En ese caso usamos la API de GitHub Releases de IzzelAliz/Arclight.
     */
    private function fetchArclight(): array
    {
        // Intento 1 — Maven oficial
        try {
            return $this->fetchMaven(
                'https://maven.islight.cc/repository/maven-public/io/izzel/arclight/arclight-forge/maven-metadata.xml',
                'Arclight Maven'
            );
        } catch (\Throwable) {
            // El Maven puede estar caído o no resolver DNS — continuamos con GitHub
        }

        // Intento 2 — GitHub Releases API (no requiere auth para 60 req/h)
        $res = Http::timeout(15)->withoutVerifying()
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get('https://api.github.com/repos/IzzelAliz/Arclight/releases', [
                'per_page' => 100,
            ]);

        $this->assertOk($res, 'Arclight GitHub Releases');

        $versions = collect($res->json() ?? [])
            ->filter(fn($r) => !($r['prerelease'] ?? false) && !($r['draft'] ?? false))
            ->pluck('tag_name')
            ->filter(fn($v) => is_string($v) && $v !== '')
            ->values()
            ->all();

        $this->assertNotEmpty($versions, 'Arclight GitHub Releases', $res->body());

        return $versions;
    }

    /**
     * Nukkit — Jenkins CI con fallback a GitHub Releases.
     *
     * Primario: Jenkins CI de OpenCollab devuelve build numbers en formato "build-{n}".
     * Fallback: GitHub Releases de CloudburstMC/Nukkit (tag_name).
     *
     * El error TLS SNI con ci.opencollab.io en algunos entornos Windows/dev
     * activa el fallback automáticamente.
     */
    private function fetchNukkit(): array
    {
        // ── Intento 1: Jenkins CI ─────────────────────────────────────────────
        try {
            $res = Http::timeout(20)
                ->withoutVerifying()
                ->withOptions(['verify' => false, 'curl' => [
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_SSL_VERIFYPEER => 0,
                ]])
                ->get('https://ci.opencollab.io/job/NukkitProject/job/Nukkit/job/master/api/json', [
                    'tree' => 'builds[number,result]{0,100}',
                ]);

            if ($res->successful()) {
                $builds = collect($res->json('builds', []))
                    ->where('result', 'SUCCESS')
                    ->pluck('number')
                    ->filter()
                    ->map(fn($n) => "build-{$n}")
                    ->values()
                    ->all();

                if (!empty($builds)) {
                    return $builds;
                }
            }
        } catch (\Throwable) {
            // TLS/red falla — continuamos con fallback
        }

        // ── Intento 2: GitHub Releases (CloudburstMC/Nukkit) ─────────────────
        $res = Http::timeout(15)->withoutVerifying()
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get('https://api.github.com/repos/CloudburstMC/Nukkit/releases', [
                'per_page' => 100,
            ]);

        $this->assertOk($res, 'Nukkit GitHub Releases');

        $versions = collect($res->json() ?? [])
            ->filter(fn($r) => !($r['prerelease'] ?? false) && !($r['draft'] ?? false))
            ->pluck('tag_name')
            ->filter(fn($v) => is_string($v) && $v !== '')
            ->values()
            ->all();

        $this->assertNotEmpty($versions, 'Nukkit GitHub Releases', $res->body());

        return $versions;
    }

    /**
     * Spiget API — recurso parametrizable.
     * Ordenado por releaseDate descendente.
     *
     * Recurso 2 = BungeeCord en SpigotMC.
     */
    private function fetchSpiget(int $resourceId, string $label): array
    {
        $res = Http::timeout(15)->withoutVerifying()
            ->get("https://api.spiget.org/v2/resources/{$resourceId}/versions", [
                'size'   => 100,
                'sort'   => '-releaseDate',
                'fields' => 'name,releaseDate',
            ]);

        $this->assertOk($res, "Spiget ({$label})");

        $versions = collect($res->json() ?? [])
            ->pluck('name')
            ->filter(fn($v) => is_string($v) && $v !== '')
            ->unique()
            ->values()
            ->all();

        $this->assertNotEmpty($versions, "Spiget ({$label})", $res->body());

        return $versions;
    }

    /**
     * SpigotMC Hub — directorio de versiones públicas.
     * GET https://hub.spigotmc.org/versions/
     * Extrae nombres de archivo {version}.json con regex.
     */
    private function fetchSpigotHub(): array
    {
        $res = Http::timeout(15)->withoutVerifying()
            ->get('https://hub.spigotmc.org/versions/');

        $this->assertOk($res, 'SpigotMC Hub');

        preg_match_all('/"(\d+\.\d+(?:\.\d+)?)\.json"/', $res->body(), $matches);
        $versions = $matches[1] ?? [];

        $this->assertNotEmpty($versions, 'SpigotMC Hub', '');

        usort($versions, fn($a, $b) => version_compare($b, $a));

        return array_values($versions);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function assertOk(\Illuminate\Http\Client\Response $res, string $label): void
    {
        if ($res->failed()) {
            throw new \RuntimeException(
                "{$label} respondió HTTP {$res->status()}. Body: " . substr($res->body(), 0, 300)
            );
        }
    }

    private function assertNotEmpty(array $versions, string $label, string $body): void
    {
        if (empty($versions)) {
            throw new \RuntimeException(
                "{$label}: respuesta OK pero sin versiones. Body: " . substr($body, 0, 300)
            );
        }
    }

    private function resolveSource(string $id): string
    {
        return match (true) {
            in_array($id, ['paper', 'velocity', 'folia'], true) => "https://api.papermc.io/v2/projects/{$id}",
            in_array($id, ['purpur', 'purpur-geyser'], true)    => 'https://api.purpurmc.org/v2/purpur',
            in_array($id, ['vanilla', 'bedrock'], true)         => 'https://launchermeta.mojang.com/mc/game/version_manifest.json',
            $id === 'fabric'      => 'https://meta.fabricmc.net/v1/versions/game',
            $id === 'quilt'       => 'https://meta.quiltmc.org/v3/versions/game',
            $id === 'forge'       => 'https://maven.minecraftforge.net/net/minecraftforge/forge/maven-metadata.xml',
            $id === 'neoforge'    => 'https://maven.neoforged.net/releases/net/neoforged/neoforge/maven-metadata.xml',
            $id === 'arclight'    => 'https://github.com/IzzelAliz/Arclight/releases',
            $id === 'sponge'      => 'https://repo.spongepowered.org/repository/maven-public/org/spongepowered/spongevanilla/maven-metadata.xml',
            $id === 'bungeecord'  => 'https://api.spiget.org/v2/resources/2/versions',
            $id === 'spigot'      => 'https://hub.spigotmc.org/versions/',
            $id === 'nukkit'      => 'https://ci.opencollab.io/job/NukkitProject/job/Nukkit/job/master/api/json',
            default               => "https://api.papermc.io/v2/projects/{$id}",
        };
    }
}
