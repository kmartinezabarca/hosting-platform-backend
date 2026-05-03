<?php

namespace App\Services\Minecraft;

use App\Models\Service;
use App\Services\GameServers\GameServerRuntimeService;
use App\Services\Pterodactyl\PterodactylService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MinecraftServerConfigurationService
{
    private const PROPERTY_FILE = '/server.properties';

    private const PROPERTY_KEY_MAP = [
        'max_players'          => 'max-players',
        'gamemode'             => 'gamemode',
        'difficulty'           => 'difficulty',
        'white_list'           => 'white-list',
        'online_mode'          => 'online-mode',
        'allow_flight'         => 'allow-flight',
        'pvp'                  => 'pvp',
        'spawn_protection'     => 'spawn-protection',
        'motd'                 => 'motd',
        'resource_pack'        => 'resource-pack',
        'resource_pack_prompt' => 'resource-pack-prompt',
    ];

    private const DEFAULT_PROPERTIES = [
        'max_players'          => 20,
        'gamemode'             => 'survival',
        'difficulty'           => 'easy',
        'white_list'           => false,
        'online_mode'          => true,
        'allow_flight'         => false,
        'pvp'                  => true,
        'spawn_protection'     => 16,
        'motd'                 => 'A Minecraft Server',
        'resource_pack'        => '',
        'resource_pack_prompt' => '',
    ];

    public function __construct(
        private readonly PterodactylService        $pterodactyl,
        private readonly MinecraftVersionService   $versions,
        private readonly GameServerRuntimeService  $runtimeOptions
    ) {}

    // ─────────────────────────────────────────────────────────────────
    // PUBLIC
    // ─────────────────────────────────────────────────────────────────

    public function configuration(Service $service): array
    {
        $service->loadMissing('plan');

        $runtime = $this->runtime($service);

        return [
            'runtime'               => $runtime,
            'server_properties'     => $this->readServerProperties($service),
            'restart_required'      => (bool) $service->restart_required,
            'pending_changes_count' => (int)  $service->pending_changes_count,
        ];
    }

    public function updateSoftware(Service $service, string $software, string $version): array
    {
        $this->runtimeOptions->assertSupported($service, $software, $version);

        $dockerImage = $this->dockerImageFor($version);

        $runtime = array_merge($this->runtime($service), [
            'software'        => $software,
            'version'         => $version,
            'docker_image'    => $dockerImage,
            'java_version'    => $this->javaVersionFor($dockerImage),
            'startup_command' => config('minecraft.defaults.startup_command'),
        ]);

        $this->syncRuntimeToPterodactyl($service, $runtime);

        $configuration = $service->configuration ?? [];
        Arr::set($configuration, 'game_server.runtime', $runtime);

        $service->forceFill([
            'configuration'         => $configuration,
            'restart_required'      => true,
            'pending_changes_count' => ((int) $service->pending_changes_count) + 1,
        ])->save();

        return $this->configuration($service->fresh());
    }

    public function updateServerProperties(Service $service, array $payload): array
    {
        $validated = $this->validateProperties($payload);

        $raw        = $this->pterodactyl->readServerFile($this->identifier($service), self::PROPERTY_FILE);
        $properties = $this->parseProperties($raw);

        foreach ($validated as $frontendKey => $value) {
            $properties[self::PROPERTY_KEY_MAP[$frontendKey]] = $this->serializePropertyValue($value);
        }

        $this->pterodactyl->writeServerFile(
            $this->identifier($service),
            self::PROPERTY_FILE,
            $this->buildPropertiesFile($properties)
        );

        $service->forceFill([
            'restart_required'      => true,
            'pending_changes_count' => ((int) $service->pending_changes_count) + 1,
        ])->save();

        return $this->configuration($service->fresh());
    }

    public function validateProperties(array $payload): array
    {
        return Validator::make($payload, [
            'max_players'          => ['sometimes', 'integer', 'min:1', 'max:500'],
            'gamemode'             => ['sometimes', 'string', Rule::in(['survival', 'creative', 'adventure', 'spectator'])],
            'difficulty'           => ['sometimes', 'string', Rule::in(['peaceful', 'easy', 'normal', 'hard'])],
            'white_list'           => ['sometimes', 'boolean'],
            'online_mode'          => ['sometimes', 'boolean'],
            'allow_flight'         => ['sometimes', 'boolean'],
            'pvp'                  => ['sometimes', 'boolean'],
            'spawn_protection'     => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'motd'                 => ['sometimes', 'string', 'max:120'],
            'resource_pack'        => ['sometimes', 'nullable', 'url'],
            'resource_pack_prompt' => ['sometimes', 'nullable', 'string', 'max:200'],
        ])->validate();
    }

    // ─────────────────────────────────────────────────────────────────
    // PRIVATE — Runtime
    // ─────────────────────────────────────────────────────────────────

    private function runtime(Service $service): array
    {
        $configured      = Arr::get($service->configuration, 'game_server.runtime', []);
        $planEnvironment = $service->plan?->pterodactyl_environment ?? [];
        $server          = $this->pterodactylServerSnapshot($service);
        $serverEnv       = $server['environment'] ?? [];

        $software = $configured['software']
            ?? $this->firstEnvironmentValue($serverEnv, config('minecraft.pterodactyl.software_variable_aliases', []))
            ?? $planEnvironment[config('minecraft.pterodactyl.software_variable')]
            ?? config('minecraft.defaults.software');

        $version =
            $serverEnv[config('minecraft.pterodactyl.version_variable')]
            ?? $configured['version']
            ?? null;

        // Calcular docker image una sola vez para derivar java_version
        $dockerImage = $configured['docker_image']
            ?? $server['docker_image']
            ?? $service->plan?->pterodactyl_docker_image
            ?? $this->dockerImageFor($version);

        return [
            'software'        => $software,
            'version'         => $version,
            'docker_image'    => $dockerImage,
            'java_version'    => $this->javaVersionFor($dockerImage),
            'nest_id'         => $server['nest_id']
                ?? $service->plan?->pterodactyl_nest_id
                ?? null,
            'egg_id'          => $server['egg_id']
                ?? $service->plan?->pterodactyl_egg_id
                ?? null,
        ];
    }

    private function syncRuntimeToPterodactyl(Service $service, array $runtime): void
    {
        if (! $service->pterodactyl_server_id) {
            return;
        }

        $server      = $this->pterodactyl->getServer($service->pterodactyl_server_id);
        $attributes  = $server['attributes'] ?? [];
        $environment = $attributes['container']['environment']
            ?? $service->plan?->pterodactyl_environment
            ?? [];

        $environment[config('minecraft.pterodactyl.version_variable')]  = $runtime['version'];
        $environment[config('minecraft.pterodactyl.software_variable')]  = $runtime['software'];
        $environment[config('minecraft.pterodactyl.jarfile_variable')]   = config('minecraft.defaults.server_jarfile');

        $this->pterodactyl->updateServerStartup(
            $service->pterodactyl_server_id,
            $environment,
            $runtime['startup_command'],
            $attributes['egg'] ?? $service->plan?->pterodactyl_egg_id,
            $runtime['docker_image']
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // PRIVATE — Server Properties
    // ─────────────────────────────────────────────────────────────────

    private function readServerProperties(Service $service): array
    {
        $raw    = $this->pterodactyl->readServerFile($this->identifier($service), self::PROPERTY_FILE);
        $parsed = $this->parseProperties($raw);

        return collect(self::PROPERTY_KEY_MAP)
            ->mapWithKeys(fn(string $pterodactylKey, string $frontendKey) => [
                $frontendKey => $this->castPropertyValue(
                    $frontendKey,
                    $parsed[$pterodactylKey] ?? self::DEFAULT_PROPERTIES[$frontendKey]
                ),
            ])
            ->all();
    }

    private function parseProperties(string $raw): array
    {
        $properties = [];

        foreach (preg_split('/\R/', $raw) as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value]      = explode('=', $line, 2);
            $properties[trim($key)] = trim($value);
        }

        return $properties;
    }

    private function buildPropertiesFile(array $properties): string
    {
        $lines = [
            '# Minecraft server properties',
            '# Managed by ROKE Industries',
        ];

        foreach ($properties as $key => $value) {
            $lines[] = "{$key}={$value}";
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function castPropertyValue(string $key, mixed $value): mixed
    {
        return match ($key) {
            'max_players',
            'spawn_protection'                  => (int) $value,
            'white_list', 'online_mode',
            'allow_flight', 'pvp'               => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default                             => (string) $value,
        };
    }

    private function serializePropertyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    // ─────────────────────────────────────────────────────────────────
    // PRIVATE — Docker / Java
    // ─────────────────────────────────────────────────────────────────

    /**
     * Retorna la imagen Docker correcta según la versión de Minecraft.
     *
     * Minecraft >= 1.20.5 requiere Java 21
     * Minecraft >= 1.17   requiere Java 17 (mínimo)
     * Minecraft < 1.17    puede correr en Java 8/11 pero usamos 17 como base
     */
    private function dockerImageFor(?string $version): string
    {
        if ($version && version_compare($version, '1.20.5', '>=')) {
            return 'ghcr.io/pterodactyl/yolks:java_21';
        }

        return 'ghcr.io/pterodactyl/yolks:java_17';
    }

    /**
     * Extrae la versión de Java del tag de la imagen Docker.
     * ghcr.io/pterodactyl/yolks:java_21 → 21
     * ghcr.io/pterodactyl/yolks:java_17 → 17
     *
     * Esto permite mostrarle al usuario qué versión de Java
     * usa su servidor actualmente antes de cambiar la versión
     * de Minecraft, evitando confusiones.
     */
    private function javaVersionFor(string $dockerImage): int
    {
        if (preg_match('/java_(\d+)/', $dockerImage, $matches)) {
            return (int) $matches[1];
        }

        // Fallback si la imagen no sigue el patrón estándar de Pterodactyl
        return 17;
    }

    // ─────────────────────────────────────────────────────────────────
    // PRIVATE — Helpers
    // ─────────────────────────────────────────────────────────────────

    private function pterodactylServerSnapshot(Service $service): array
    {
        if (! $service->pterodactyl_server_id) {
            return [];
        }

        try {
            $server     = $this->pterodactyl->getServer($service->pterodactyl_server_id);
            $attributes = $server['attributes'] ?? [];

            return [
                'docker_image'    => $attributes['container']['image']            ?? null,
                'startup_command' => $attributes['container']['startup_command']  ?? null,
                'environment'     => $attributes['container']['environment']      ?? [],
                'egg_id'          => $attributes['egg']                          ?? null,
                'nest_id'         => $attributes['nest']                         ?? null,
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    private function latestPlanVersion(Service $service, string $software): ?string
    {
        $option = collect($this->runtimeOptions->softwareOptions($service))
            ->firstWhere('id', $software);

        return $option['versions'][0] ?? null;
    }

    private function firstEnvironmentValue(array $environment, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! $key) {
                continue;
            }

            $value = $environment[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function identifier(Service $service): string
    {
        $identifier = $service->connection_details['identifier'] ?? null;

        if (! $identifier) {
            throw new RuntimeException('El servidor no tiene un identificador asignado.');
        }

        return $identifier;
    }
}
