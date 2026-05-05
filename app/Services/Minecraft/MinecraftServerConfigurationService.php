<?php

namespace App\Services\Minecraft;

use App\Models\Service;
use App\Services\GameServers\GameServerRuntimeService;
use App\Services\Pterodactyl\PterodactylService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Gestión completa de la configuración de servidores de juego basados en Minecraft.
 *
 * Responsabilidades:
 *   - Leer / escribir server.properties y eula.txt via Pterodactyl Files API
 *   - Resolver la imagen Docker de Java correcta para cada versión de Minecraft
 *   - Detectar incompatibilidades de Java leyendo los logs del servidor
 *   - Cambiar software (Paper ↔ Purpur ↔ Fabric ↔ Forge ↔ Vanilla) reinstalando el egg
 *   - Limpiar archivos de forma selectiva (versión) o total (egg diferente)
 *   - Corregir automáticamente la imagen Docker cuando hay mismatch
 */
class MinecraftServerConfigurationService
{
    // ── Archivos del servidor ─────────────────────────────────────────────────
    private const PROPERTY_FILE = '/server.properties';
    private const EULA_FILE     = '/eula.txt';
    private const LOG_FILE      = '/logs/latest.log';
    private const LOG_LINES     = 200;          // últimas líneas del log a analizar

    // ── Mapa frontend_key → server.properties key ────────────────────────────
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

    // ── Patrones de error en logs que indican incompatibilidad de Java ────────
    //
    // Cada entrada: patrón regex => tipo de error
    // Tipos con grupo de captura (1) permiten extraer el número concreto.
    //
    private const JAVA_LOG_ERROR_PATTERNS = [
        // "class file version 65.0" o "class file version 65"
        '/class file version (\d+)(?:\.0)?/'                               => 'class_major_version',
        // "Unsupported class file major version 65"
        '/[Uu]nsupported class file major version\s+(\d+)/'                => 'class_major_version',
        // "has been compiled by a more recent version of the Java Runtime"
        '/compiled by a more recent version of the Java Runtime/'           => 'too_old_java',
        // UnsupportedClassVersionError
        '/UnsupportedClassVersionError/'                                    => 'too_old_java',
        // "Error: Could not find or load main class"
        '/Error: Could not find or load main class/'                        => 'startup_failed',
        // "Error: A JNI error has occurred"
        '/Error: A JNI error has occurred/'                                 => 'jni_error',
        // "Could not create the Java Virtual Machine"
        '/Could not create the Java Virtual Machine/'                       => 'jvm_creation_failed',
        // "requires minimum JVM version 21" o "requires JVM version 21"
        '/requires (?:minimum )?JVM version\s+(\d+)/'                      => 'explicit_jvm_version',
        // "Java 21 or above required"
        '/Java (\d+) or (?:above|higher) required/'                        => 'explicit_jvm_version',
    ];

    // ── Base URL de las imágenes Pterodactyl Yolks ────────────────────────────
    private const YOLKS_BASE = 'ghcr.io/pterodactyl/yolks:';

    public function __construct(
        private readonly PterodactylService       $pterodactyl,
        private readonly MinecraftVersionService  $versions,
        private readonly GameServerRuntimeService $runtimeOptions
    ) {}

    // ═════════════════════════════════════════════════════════════════════════
    // API PÚBLICA
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Devuelve la configuración completa del servidor para el frontend.
     */
    public function configuration(Service $service): array
    {
        $service->loadMissing(['plan', 'selectedEgg']);

        $runtime       = $this->runtime($service);
        $correctDocker = $this->dockerImageFor($runtime['version'] ?? null);
        $currentDocker = $runtime['docker_image'] ?? '';
        $javaMatch     = $this->normalizeDockerTag($currentDocker) === $this->normalizeDockerTag($correctDocker);

        return [
            'runtime'               => $runtime,
            'server_properties'     => $this->readServerProperties($service),
            'eula_accepted'         => $this->readEulaAccepted($service),
            'java_version_mismatch' => ! $javaMatch,
            'current_java'          => $this->javaVersionFor($currentDocker),
            'required_java'         => $this->javaVersionFor($correctDocker),
            'required_docker_image' => $correctDocker,
            'restart_required'      => (bool) $service->restart_required,
            'pending_changes_count' => (int)  $service->pending_changes_count,
        ];
    }

    /**
     * Lee los logs del servidor y detecta errores de compatibilidad de Java.
     *
     * @return array{
     *   has_error: bool,
     *   error_type: string|null,
     *   current_java: int,
     *   required_java: int|null,
     *   current_docker: string,
     *   required_docker: string|null,
     *   log_snippet: string[],
     *   message: string,
     * }
     */
    public function detectJavaCompatibilityFromLogs(Service $service): array
    {
        $service->loadMissing(['plan', 'selectedEgg']);

        $identifier    = $this->identifier($service);
        $runtime       = $this->runtime($service);
        $currentDocker = $runtime['docker_image'] ?? config('minecraft.defaults.docker_image');
        $currentJava   = $this->javaVersionFor($currentDocker);

        $noError = [
            'has_error'       => false,
            'error_type'      => null,
            'current_java'    => $currentJava,
            'required_java'   => null,
            'current_docker'  => $currentDocker,
            'required_docker' => null,
            'log_snippet'     => [],
            'message'         => 'No se detectaron errores de Java en los logs.',
        ];

        // ── 1. Leer log ──────────────────────────────────────────────────────
        try {
            $rawLog = $this->pterodactyl->readServerFile($identifier, self::LOG_FILE);
        } catch (\Throwable $e) {
            Log::info('detectJavaCompatibilityFromLogs: log no disponible', [
                'service_id' => $service->id,
                'error'      => $e->getMessage(),
            ]);
            return array_merge($noError, [
                'message' => 'El log aún no existe (servidor sin iniciar o recién instalado).',
            ]);
        }

        // Tomar las últimas N líneas para no procesar logs enormes
        $lines   = preg_split('/\R/', $rawLog);
        $snippet = array_slice($lines, -self::LOG_LINES);

        // ── 2. Buscar patrones ───────────────────────────────────────────────
        $detectedType     = null;
        $detectedMajor    = null;   // class file major version number (si se captura)
        $detectedExplicit = null;   // versión JVM explícita (si se captura)
        $matchedLines     = [];

        foreach ($snippet as $line) {
            foreach (self::JAVA_LOG_ERROR_PATTERNS as $pattern => $type) {
                if (preg_match($pattern, $line, $m)) {
                    $matchedLines[] = trim($line);

                    if ($type === 'class_major_version' && isset($m[1]) && $detectedMajor === null) {
                        $detectedMajor = (int) $m[1];
                        $detectedType  = $type;
                    } elseif ($type === 'explicit_jvm_version' && isset($m[1]) && $detectedExplicit === null) {
                        $detectedExplicit = (int) $m[1];
                        $detectedType     = $type;
                    } elseif ($detectedType === null) {
                        $detectedType = $type;
                    }
                }
            }
        }

        if ($detectedType === null) {
            return $noError;
        }

        // ── 3. Calcular la versión de Java requerida ─────────────────────────
        $requiredJava = null;

        if ($detectedMajor !== null) {
            // Java SE class major version: 52=Java8, 55=Java11, 60=Java16, 61=Java17, 65=Java21...
            // Fórmula general: java = major - 44 (válida desde Java 8)
            $map          = config('minecraft.class_major_version_map', []);
            $requiredJava = $map[$detectedMajor] ?? max(8, $detectedMajor - 44);
        } elseif ($detectedExplicit !== null) {
            $requiredJava = $detectedExplicit;
        } elseif (in_array($detectedType, ['too_old_java', 'jni_error', 'jvm_creation_failed'], true)) {
            // Error genérico de Java viejo → requerir el Java correcto para esta versión MC
            $requiredDocker = $this->dockerImageFor($runtime['version'] ?? null);
            $requiredJava   = $this->javaVersionFor($requiredDocker);
        }

        if ($requiredJava === null) {
            return array_merge($noError, [
                'has_error'   => true,
                'error_type'  => $detectedType,
                'log_snippet' => array_values(array_unique($matchedLines)),
                'message'     => "Error de Java detectado (tipo: {$detectedType}) pero no se pudo determinar la versión exacta.",
            ]);
        }

        $requiredDocker = $this->dockerImageForJava($requiredJava);

        return [
            'has_error'       => true,
            'error_type'      => $detectedType,
            'current_java'    => $currentJava,
            'required_java'   => $requiredJava,
            'current_docker'  => $currentDocker,
            'required_docker' => $requiredDocker,
            'log_snippet'     => array_values(array_unique($matchedLines)),
            'message'         => "Java {$currentJava} no es compatible. Se requiere Java {$requiredJava} ({$requiredDocker}).",
        ];
    }

    /**
     * Detecta desde los logs si hay un error de Java y lo corrige automáticamente.
     *
     * @return array  Resultado combinado de detectJavaCompatibilityFromLogs + fixJavaVersion
     */
    public function checkAndFixJavaCompatibility(Service $service): array
    {
        $detection = $this->detectJavaCompatibilityFromLogs($service);

        if (! $detection['has_error'] || ! $detection['required_java']) {
            return array_merge($detection, ['fix_applied' => false]);
        }

        $fix = $this->fixJavaVersion($service, $detection['required_java']);

        Log::info('checkAndFixJavaCompatibility: corrección aplicada', [
            'service_id' => $service->id,
            'error_type' => $detection['error_type'],
            'old_java'   => $detection['current_java'],
            'new_java'   => $detection['required_java'],
        ]);

        return array_merge($detection, $fix, ['fix_applied' => $fix['fixed']]);
    }

    /**
     * Corrige la imagen Docker del servidor para que coincida con la versión
     * de Java requerida. NO reinstala el servidor (skip_scripts=true).
     *
     * @param  int|null $targetJava  Versión de Java deseada. Null → calcular desde la versión MC.
     */
    public function fixJavaVersion(Service $service, ?int $targetJava = null): array
    {
        $service->loadMissing(['plan', 'selectedEgg']);

        $runtime       = $this->runtime($service);
        $currentDocker = $runtime['docker_image'] ?? config('minecraft.defaults.docker_image');

        $correctDocker = $targetJava
            ? $this->dockerImageForJava($targetJava)
            : $this->dockerImageFor($runtime['version'] ?? null);

        $oldJava = $this->javaVersionFor($currentDocker);
        $newJava = $this->javaVersionFor($correctDocker);

        if ($this->normalizeDockerTag($currentDocker) === $this->normalizeDockerTag($correctDocker)) {
            return [
                'fixed'        => false,
                'old_java'     => $oldJava,
                'new_java'     => $newJava,
                'docker_image' => $correctDocker,
                'message'      => "La versión de Java ya es correcta (Java {$newJava}).",
            ];
        }

        if (! $service->pterodactyl_server_id) {
            throw new RuntimeException('El servidor no tiene un ID de Pterodactyl asignado.');
        }

        $server      = $this->pterodactyl->getServer($service->pterodactyl_server_id);
        $attributes  = $server['attributes'] ?? [];
        $environment = $attributes['container']['environment']
            ?? $service->plan?->pterodactyl_environment
            ?? [];

        // Egg ID desde el egg seleccionado por el cliente (multi-game)
        $eggId   = (int) ($attributes['egg'] ?? $service->selectedEgg?->ptero_egg_id ?? 0);
        $startup = $attributes['container']['startup_command']
            ?? $service->selectedEgg?->startup
            ?? config('minecraft.defaults.startup_command');

        if (! $eggId) {
            throw new RuntimeException('No se pudo determinar el egg del servidor para actualizar la imagen Docker.');
        }

        // skip_scripts=true: solo cambia la imagen, NO reinstala ni descarga JAR
        $this->pterodactyl->updateServerStartup(
            $service->pterodactyl_server_id,
            $environment,
            $startup,
            $eggId,
            $correctDocker,
            skipScripts: true
        );

        // Persistir en nuestra BD
        $configuration = $service->configuration ?? [];
        Arr::set($configuration, 'game_server.runtime.docker_image', $correctDocker);
        Arr::set($configuration, 'game_server.runtime.java_version', $newJava);
        $service->forceFill(['configuration' => $configuration])->save();

        Log::info('fixJavaVersion: docker image actualizado', [
            'service_id' => $service->id,
            'old_image'  => $currentDocker,
            'new_image'  => $correctDocker,
            'old_java'   => $oldJava,
            'new_java'   => $newJava,
        ]);

        return [
            'fixed'        => true,
            'old_java'     => $oldJava,
            'new_java'     => $newJava,
            'docker_image' => $correctDocker,
            'message'      => "Imagen actualizada de Java {$oldJava} → Java {$newJava}. Reinicia el servidor para aplicar el cambio.",
        ];
    }

    /**
     * Devuelve true si eula.txt del servidor contiene "eula=true".
     */
    public function eulaAccepted(Service $service): bool
    {
        return $this->readEulaAccepted($service);
    }

    /**
     * Escribe eula=true en eula.txt del servidor.
     */
    public function acceptEula(Service $service): void
    {
        $identifier = $this->identifier($service);

        $content = implode(PHP_EOL, [
            '#By changing the setting below to TRUE you are indicating your agreement to our EULA',
            '#(https://aka.ms/MinecraftEULA)',
            '#' . now()->toDateTimeString(),
            'eula=true',
        ]) . PHP_EOL;

        $this->pterodactyl->writeServerFile($identifier, self::EULA_FILE, $content);
    }

    /**
     * Cambia el software del servidor (Paper → Fabric, etc.) y/o la versión de Minecraft.
     */
    public function updateSoftware(Service $service, string $software, string $version): array
    {
        $service->loadMissing(['plan', 'selectedEgg']);

        $this->runtimeOptions->assertSupported($service, $software, $version);

        $dockerImage  = $this->dockerImageFor($version);
        $softwareName = $this->resolveSoftwareName($service, $software);

        $runtime = array_merge($this->runtime($service), [
            'software'        => $software,
            'software_name'   => $softwareName,
            'version'         => $version,
            'docker_image'    => $dockerImage,
            'java_version'    => $this->javaVersionFor($dockerImage),
            'startup_command' => config('minecraft.defaults.startup_command'),
        ]);

        $reinstallTriggered = $this->syncRuntimeToPterodactyl($service, $runtime);

        $configuration = $service->configuration ?? [];
        Arr::set($configuration, 'game_server.runtime', $runtime);

        $service->forceFill([
            'configuration'         => $configuration,
            'restart_required'      => true,
            'pending_changes_count' => ((int) $service->pending_changes_count) + 1,
        ])->save();

        return array_merge(
            $this->configuration($service->fresh()),
            ['reinstall_triggered' => $reinstallTriggered]
        );
    }

    /**
     * Actualiza server.properties.
     */
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

    /**
     * Valida propiedades del servidor antes de escribirlas.
     */
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

    // ═════════════════════════════════════════════════════════════════════════
    // JAVA / DOCKER IMAGE — API pública de utilidad
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Retorna la imagen Docker correcta según la versión de Minecraft.
     *
     * Umbrales (de mayor a menor, configurables en config/minecraft.php):
     *   >= 1.22         → java_21  (pendiente java_25 cuando sea estable en producción)
     *   >= 1.20.5       → java_21
     *   >= 1.18         → java_17
     *   >= 1.17         → java_16  ← 1.17 requiere Java 16 EXACTO o superior
     *   <  1.17 (1.7+)  → java_8
     *
     * Strings no-semánticos ("latest", "build-673", etc.) → java_21 (seguro para versiones modernas).
     */
    public function dockerImageFor(?string $version): string
    {
        if (! $version) {
            return self::YOLKS_BASE . 'java_21';
        }

        // Extraer solo el fragmento semántico X.Y[.Z] del string
        // Ej: "1.21.4-build2" → "1.21.4", "1.17.1" → "1.17.1"
        if (! preg_match('/^(\d+\.\d+(?:\.\d+)?)/', $version, $m)) {
            // "latest", "build-673", etc. → imagen moderna segura
            return self::YOLKS_BASE . 'java_21';
        }

        $semver     = $m[1];
        $thresholds = config('minecraft.java_thresholds', []);

        // Recorrer thresholds de mayor a menor (orden definido en el config)
        foreach ($thresholds as $threshold) {
            if (version_compare($semver, $threshold['min_version'], '>=')) {
                $tag = $threshold['yolks_tag'];
                $this->warnIfTagUnavailable($tag);
                return self::YOLKS_BASE . $tag;
            }
        }

        // Fallback: Minecraft < 1.17 → Java 8
        $legacy = config('minecraft.java_legacy', ['yolks_tag' => 'java_8']);
        return self::YOLKS_BASE . $legacy['yolks_tag'];
    }

    /**
     * Retorna la imagen Docker para una versión de Java concreta.
     *
     * Si la versión solicitada no existe en las imágenes disponibles, toma la
     * más cercana POR ARRIBA (Java 15 → java_16, Java 20 → java_21).
     *
     * Java es retrocompatible hacia atrás: Java 21 puede ejecutar bytecode
     * compilado para Java 11. Por eso "redondear arriba" es siempre seguro.
     */
    public function dockerImageForJava(int $javaVersion): string
    {
        $available = $this->availableJavaVersionNumbers();

        // Versión exacta disponible
        if (in_array($javaVersion, $available, true)) {
            return self::YOLKS_BASE . "java_{$javaVersion}";
        }

        // La más pequeña que sea >= $javaVersion (redondear ARRIBA)
        $suitable = collect($available)
            ->filter(fn(int $v) => $v >= $javaVersion)
            ->first();

        if ($suitable !== null) {
            return self::YOLKS_BASE . "java_{$suitable}";
        }

        // Pedimos algo más nuevo que lo máximo disponible → la más reciente
        $latest = collect($available)->last();

        Log::warning("dockerImageForJava: Java {$javaVersion} no disponible en Yolks, usando java_{$latest}", [
            'requested' => $javaVersion,
            'available' => $available,
            'fallback'  => $latest,
        ]);

        return self::YOLKS_BASE . "java_{$latest}";
    }

    /**
     * Extrae el número de versión de Java del tag de la imagen Docker.
     * "ghcr.io/pterodactyl/yolks:java_21" → 21
     * "java_8"  → 8
     * "java_16" → 16
     */
    public function javaVersionFor(string $dockerImage): int
    {
        if (preg_match('/java_(\d+)/i', $dockerImage, $m)) {
            return (int) $m[1];
        }

        Log::warning("javaVersionFor: no se pudo extraer versión de Java de '{$dockerImage}', asumiendo 17");
        return 17; // fallback seguro y compatible con la mayoría de versiones modernas
    }

    /**
     * Devuelve los números de versión de Java disponibles como array ordenado ascendente.
     * Ej: [8, 11, 16, 17, 21, 22, 23, 24, 25]
     */
    public function availableJavaVersionNumbers(): array
    {
        return collect(config('minecraft.available_yolks_tags', [
            'java_8', 'java_11', 'java_16', 'java_17', 'java_21',
        ]))
            ->map(function (string $tag): ?int {
                return preg_match('/java_(\d+)/i', $tag, $m) ? (int) $m[1] : null;
            })
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Devuelve la tabla completa de requisitos de Java para el frontend.
     */
    public function javaRequirementsTable(): array
    {
        $thresholds = config('minecraft.java_thresholds', []);
        $legacy     = config('minecraft.java_legacy', ['java' => 8, 'yolks_tag' => 'java_8', 'label' => 'Java 8 (< 1.17)']);

        $rows = collect($thresholds)->map(fn($t) => [
            'min_mc_version' => $t['min_version'],
            'java_version'   => $t['java'],
            'docker_tag'     => $t['yolks_tag'],
            'label'          => $t['label'],
        ])->all();

        $rows[] = [
            'min_mc_version' => '0',
            'java_version'   => $legacy['java'],
            'docker_tag'     => $legacy['yolks_tag'],
            'label'          => $legacy['label'],
        ];

        return $rows;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PRIVATE — Runtime
    // ═════════════════════════════════════════════════════════════════════════

    private function runtime(Service $service): array
    {
        $service->loadMissing(['plan', 'selectedEgg']);

        $configured      = Arr::get($service->configuration, 'game_server.runtime', []);
        $planEnvironment = $service->plan?->pterodactyl_environment ?? [];
        $server          = $this->pterodactylServerSnapshot($service);
        $serverEnv       = $server['environment'] ?? [];

        $software = $configured['software']
            ?? $this->firstEnvironmentValue($serverEnv, config('minecraft.pterodactyl.software_variable_aliases', []))
            ?? ($planEnvironment[config('minecraft.pterodactyl.software_variable')] ?? null)
            ?? config('minecraft.defaults.software');

        $version =
            ($serverEnv[config('minecraft.pterodactyl.version_variable')] ?? null)
            ?? $configured['version']
            ?? null;

        $dockerImage = $configured['docker_image']
            ?? $server['docker_image']
            ?? $service->plan?->pterodactyl_docker_image
            ?? $service->selectedEgg?->docker_image
            ?? $this->dockerImageFor($version);

        // ── IDs del nest/egg: usar el egg seleccionado por el cliente ──────────
        // service_plans.pterodactyl_nest_id y pterodactyl_egg_id fueron ELIMINADOS.
        // La fuente de verdad ahora es services.selected_egg_id.
        $nestId = $server['nest_id']
            ?? $service->selectedEgg?->ptero_nest_id
            ?? null;

        $eggId = $server['egg_id']
            ?? $service->selectedEgg?->ptero_egg_id
            ?? null;

        return [
            'software'     => $software,
            'version'      => $version,
            'docker_image' => $dockerImage,
            'java_version' => $this->javaVersionFor($dockerImage),
            'nest_id'      => $nestId,
            'egg_id'       => $eggId,
            'egg_name'     => $service->selectedEgg?->egg_name,
            'nest_name'    => $service->selectedEgg?->nest_name,
        ];
    }

    /**
     * Sincroniza el runtime con Pterodactyl.
     *
     * Tres casos posibles:
     *   A) Egg cambió (juego diferente):
     *      kill → limpieza TOTAL de archivos → updateStartup → reinstall
     *
     *   B) Misma versión pero imagen Docker cambió (ej: 1.21 → 1.7 cambio de Java):
     *      kill → limpiar solo JARs y librerías → updateStartup → reinstall
     *
     *   C) Solo cambió la versión (mismo Java):
     *      updateStartupVariable (Client API) + updateServerStartup (Application API)
     *      → no hay reinstall
     *
     * @return bool  true si se disparó un reinstall
     */
    private function syncRuntimeToPterodactyl(Service $service, array $runtime): bool
    {
        if (! $service->pterodactyl_server_id) {
            return false;
        }

        $service->loadMissing(['plan', 'selectedEgg']);
        $identifier = $service->connection_details['identifier'] ?? null;

        // ── Obtener estado actual del servidor en Pterodactyl ─────────────────
        $server             = $this->pterodactyl->getServer($service->pterodactyl_server_id);
        $attributes         = $server['attributes'] ?? [];
        $currentEggId       = (int) ($attributes['egg'] ?? 0);
        $currentDockerImage = $attributes['container']['image'] ?? '';

        // ── Resolver egg de destino ───────────────────────────────────────────
        // Fuente de verdad: services.selected_egg_id (columna agregada en multi-game).
        // La columna service_plans.pterodactyl_egg_id fue ELIMINADA.
        $selectedEgg = $service->selectedEgg;
        $newEggId    = is_numeric($runtime['software'] ?? null)
            ? (int) $runtime['software']
            : ($selectedEgg?->ptero_egg_id ?? $currentEggId ?: null);

        $nestId = $selectedEgg?->ptero_nest_id ?? null;

        $eggChanged         = $newEggId && ($newEggId !== $currentEggId);
        $dockerImageChanged = $this->normalizeDockerTag($currentDockerImage)
                           !== $this->normalizeDockerTag($runtime['docker_image']);

        // ── Obtener variables del egg de destino ──────────────────────────────
        $eggVars       = [];
        $eggStartup    = $runtime['startup_command'] ?? config('minecraft.defaults.startup_command');
        $versionVarKey = config('minecraft.pterodactyl.version_variable', 'MINECRAFT_VERSION');
        $resolvedEggId = $newEggId ?? $currentEggId;

        if ($resolvedEggId && $nestId) {
            try {
                $eggDetails  = $this->pterodactyl->getEggDetails($nestId, $resolvedEggId);
                $eggAttrs    = $eggDetails['attributes'] ?? [];
                $eggVars     = $eggAttrs['relationships']['variables']['data'] ?? [];

                if (! empty($eggAttrs['startup'])) {
                    $eggStartup = $eggAttrs['startup'];
                }

                $versionVarKey = $this->resolveVersionVariable($eggVars, $versionVarKey);

            } catch (\Throwable $e) {
                Log::warning('syncRuntimeToPterodactyl: no se pudo obtener detalles del egg', [
                    'service_id' => $service->id,
                    'nest_id'    => $nestId,
                    'egg_id'     => $resolvedEggId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        // ═════ CASO A: Cambio de egg (juego diferente) ═══════════════════════
        if ($eggChanged) {
            return $this->handleEggChange(
                $service, $identifier, $attributes,
                $newEggId, $eggVars, $eggStartup, $versionVarKey, $runtime
            );
        }

        // ═════ CASOS B y C: Mismo egg ════════════════════════════════════════
        return $this->handleVersionChange(
            $service, $identifier, $attributes, $currentEggId,
            $eggStartup, $versionVarKey, $runtime, $dockerImageChanged
        );
    }

    /**
     * CASO A — Cambio de egg (juego diferente).
     *
     * 1. Mata el servidor
     * 2. Borra TODOS los archivos de la raíz
     * 3. Actualiza startup con el nuevo egg
     * 4. Reinstala (descarga el JAR del nuevo juego)
     */
    private function handleEggChange(
        Service $service,
        ?string $identifier,
        array   $attributes,
        int     $newEggId,
        array   $eggVars,
        string  $eggStartup,
        string  $versionVarKey,
        array   $runtime
    ): bool {
        // 1. Detener el servidor
        if ($identifier) {
            $this->killServerSafely($service, $identifier);
        }

        // 2. Limpiar TODOS los archivos (cambio de juego = instalación desde cero)
        if ($identifier) {
            $this->cleanAllFiles($service, $identifier);
        }

        // 3. Construir environment
        $eggDefaults = $this->buildEggDefaults($eggVars);
        $currentEnv  = $attributes['container']['environment']
            ?? $service->plan?->pterodactyl_environment
            ?? [];

        // Prioridad: defaults del egg < entorno actual del server < nuestros overrides
        $environment = array_merge($eggDefaults, $currentEnv);
        if ($runtime['version']) {
            $environment[$versionVarKey] = $runtime['version'];
        }

        $jarfileVar = config('minecraft.pterodactyl.jarfile_variable', 'SERVER_JARFILE');
        $environment[$jarfileVar] = config('minecraft.defaults.server_jarfile', 'server.jar');

        // 4. Actualizar startup en Pterodactyl
        $this->pterodactyl->updateServerStartup(
            $service->pterodactyl_server_id,
            $environment,
            $eggStartup,
            $newEggId,
            $runtime['docker_image'],
            skipScripts: false  // ejecutar install script del nuevo egg
        );

        // 5. Disparar reinstall y encolar job de inicio
        $this->triggerReinstall($service, $identifier);

        Log::info('handleEggChange: egg cambiado → limpieza total + reinstall', [
            'service_id'   => $service->id,
            'new_egg_id'   => $newEggId,
            'docker_image' => $runtime['docker_image'],
            'java'         => $this->javaVersionFor($runtime['docker_image']),
            'version'      => $runtime['version'],
        ]);

        return true;
    }

    /**
     * CASOS B y C — Mismo egg, versión diferente.
     *
     * Si la imagen Docker cambió (Java diferente):
     *   kill → limpiar JARs y librerías → updateStartup con skipScripts=false → reinstall
     *
     * Si solo cambió la versión (mismo Java):
     *   updateStartupVariable (Client API) + updateServerStartup (Application API)
     *   → no hay reinstall
     */
    private function handleVersionChange(
        Service $service,
        ?string $identifier,
        array   $attributes,
        int     $currentEggId,
        string  $eggStartup,
        string  $versionVarKey,
        array   $runtime,
        bool    $dockerImageChanged
    ): bool {
        $currentEnv = $attributes['container']['environment']
            ?? $service->plan?->pterodactyl_environment
            ?? [];

        $environment = $currentEnv;
        if ($runtime['version']) {
            $environment[$versionVarKey] = $runtime['version'];
        }

        $jarfileVar = config('minecraft.pterodactyl.jarfile_variable', 'SERVER_JARFILE');
        $environment[$jarfileVar] = config('minecraft.defaults.server_jarfile', 'server.jar');

        // CASO C: solo versión cambia (mismo Java) ────────────────────────────
        if (! $dockerImageChanged) {
            // Intentar vía Client API primero (más rápido, no requiere reinicio de Wings)
            if ($identifier && $runtime['version']) {
                try {
                    $this->pterodactyl->updateStartupVariable(
                        $identifier, $versionVarKey, $runtime['version']
                    );
                } catch (\Throwable $e) {
                    Log::warning('handleVersionChange: Client API falló, usando solo Application API', [
                        'service_id' => $service->id,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            // Siempre actualizar también via Application API (persiste en BD de Pterodactyl)
            $this->pterodactyl->updateServerStartup(
                $service->pterodactyl_server_id,
                $environment,
                $eggStartup,
                $currentEggId ?: null,
                $runtime['docker_image'],
                skipScripts: true   // solo versión → no reinstalar
            );

            Log::info('handleVersionChange: versión actualizada (sin reinstall)', [
                'service_id'  => $service->id,
                'version'     => $runtime['version'],
                'docker'      => $runtime['docker_image'],
                'java'        => $this->javaVersionFor($runtime['docker_image']),
            ]);

            return false;
        }

        // CASO B: imagen Docker cambió (Java diferente) ───────────────────────
        // El JAR compilado para el Java anterior puede ser incompatible.
        // Hay que limpiar los binarios y reinstalar.
        Log::info('handleVersionChange: imagen Docker cambió → limpiando JARs + reinstall', [
            'service_id'       => $service->id,
            'new_docker_image' => $runtime['docker_image'],
            'new_java'         => $this->javaVersionFor($runtime['docker_image']),
            'version'          => $runtime['version'],
        ]);

        if ($identifier) {
            $this->killServerSafely($service, $identifier);
            $this->cleanServerJarsAndLibraries($service, $identifier);
        }

        // Actualizar startup con la nueva imagen, ejecutar install script
        $this->pterodactyl->updateServerStartup(
            $service->pterodactyl_server_id,
            $environment,
            $eggStartup,
            $currentEggId ?: null,
            $runtime['docker_image'],
            skipScripts: false  // necesita descargar el JAR correcto para el nuevo Java
        );

        $this->triggerReinstall($service, $identifier);

        return true;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PRIVATE — Limpieza de archivos
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Borra TODOS los archivos y directorios de la raíz del servidor.
     * Uso: cuando cambia el egg (juego diferente — instalación desde cero).
     */
    private function cleanAllFiles(Service $service, string $identifier): void
    {
        try {
            $files = $this->pterodactyl->listFiles($identifier, '/');

            if (empty($files)) {
                return;
            }

            $names = array_column($files, 'name');

            // Borrar en lotes de 50 para no saturar la API de Wings
            foreach (array_chunk($names, 50) as $batch) {
                try {
                    $this->pterodactyl->deleteFiles($identifier, '/', $batch);
                } catch (\Throwable $e) {
                    Log::warning('cleanAllFiles: error en lote (continuando)', [
                        'service_id' => $service->id,
                        'batch'      => $batch,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            Log::info('cleanAllFiles: raíz limpiada para cambio de egg', [
                'service_id'    => $service->id,
                'files_deleted' => count($names),
            ]);
        } catch (\Throwable $e) {
            // No bloqueante — Wings limpiará en el reinstall igualmente
            Log::warning('cleanAllFiles: error al listar archivos (no fatal)', [
                'service_id' => $service->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Borra solo los JARs del servidor, libraries/, cache/, versions/.
     * Preserva: world/, plugins/, configuraciones, whitelist, etc.
     *
     * Uso: cuando cambia la versión de Minecraft con diferente imagen Docker.
     * El JAR anterior compilado para Java X no arranca en Java Y.
     */
    private function cleanServerJarsAndLibraries(Service $service, string $identifier): void
    {
        $preserve = collect(config('minecraft.preserve_on_version_change', []));

        try {
            $rootFiles = $this->pterodactyl->listFiles($identifier, '/');

            // JARs y archivos binarios que no están en la lista de preservación
            $jarsToDelete = collect($rootFiles)
                ->filter(fn($f) => ! $preserve->contains($f['name']))
                ->filter(fn($f) => $this->isServerBinaryFile($f))
                ->pluck('name')
                ->all();

            if (! empty($jarsToDelete)) {
                foreach (array_chunk($jarsToDelete, 50) as $batch) {
                    try {
                        $this->pterodactyl->deleteFiles($identifier, '/', $batch);
                    } catch (\Throwable) { /* continuar */ }
                }
                Log::info('cleanServerJarsAndLibraries: JARs eliminados', [
                    'service_id' => $service->id,
                    'files'      => $jarsToDelete,
                ]);
            }

            // Directorios de binarios que deben borrarse
            $binaryDirs = ['libraries', 'cache', 'versions', 'jars', 'logs'];
            foreach ($binaryDirs as $dir) {
                $found = collect($rootFiles)->firstWhere('name', $dir);
                if ($found && ! ($found['is_file'] ?? true)) {
                    try {
                        $this->pterodactyl->deleteFiles($identifier, '/', [$dir]);
                        Log::info("cleanServerJarsAndLibraries: directorio '{$dir}' eliminado", [
                            'service_id' => $service->id,
                        ]);
                    } catch (\Throwable $e) {
                        Log::debug("cleanServerJarsAndLibraries: no se pudo borrar '{$dir}'", [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('cleanServerJarsAndLibraries: error general (no fatal)', [
                'service_id' => $service->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Determina si un archivo es un binario del servidor que puede borrarse
     * al cambiar de versión sin perder datos del usuario.
     */
    private function isServerBinaryFile(array $file): bool
    {
        if (! ($file['is_file'] ?? false)) {
            return false;
        }

        $name = strtolower($file['name'] ?? '');

        // JARs del servidor
        if (str_ends_with($name, '.jar')) {
            return true;
        }

        // Archivos de versión/caché de Paper, Purpur, etc.
        if (str_ends_with($name, '.json') && (
            str_contains($name, 'version') ||
            str_contains($name, 'cache')   ||
            str_contains($name, 'patch')
        )) {
            return true;
        }

        // Archivos de patch de Paper
        if (str_ends_with($name, '.patch')) {
            return true;
        }

        return false;
    }

    /**
     * Mata el servidor de forma segura y espera 0.5 s para que Wings procese la señal.
     */
    private function killServerSafely(Service $service, string $identifier): void
    {
        try {
            $this->pterodactyl->sendPowerSignal($identifier, 'kill');
            usleep(500_000); // 0.5 segundos
        } catch (\Throwable $e) {
            // El servidor puede estar ya detenido — no es un error
            Log::info('killServerSafely: señal kill ignorada (servidor posiblemente ya detenido)', [
                'service_id' => $service->id,
                'message'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Dispara reinstallServer en Pterodactyl y encola el job de inicio post-install.
     * Absorbe el error "already installing" (Pterodactyl moderno lo auto-dispara
     * cuando skip_scripts=false en updateServerStartup).
     */
    private function triggerReinstall(Service $service, ?string $identifier): void
    {
        try {
            $this->pterodactyl->reinstallServer($service->pterodactyl_server_id);
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'installation') || str_contains($msg, 'installing')) {
                Log::info('triggerReinstall: reinstall ya en curso (Pterodactyl moderno disparó automáticamente)', [
                    'service_id' => $service->id,
                ]);
            } else {
                throw $e; // Error genuino — propagar
            }
        }

        // Encolar el job que inicia el servidor una vez que el install termine
        \App\Jobs\StartServerAfterInstall::dispatch($service)->delay(now()->addSeconds(20));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PRIVATE — EULA
    // ═════════════════════════════════════════════════════════════════════════

    private function readEulaAccepted(Service $service): bool
    {
        try {
            $identifier = $this->identifier($service);
            $raw        = $this->pterodactyl->readServerFile($identifier, self::EULA_FILE);

            foreach (preg_split('/\R/', $raw) as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                    continue;
                }
                if (preg_match('/^\s*eula\s*=\s*true\s*$/i', $trimmed)) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable) {
            return false; // El archivo aún no existe
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PRIVATE — Server Properties
    // ═════════════════════════════════════════════════════════════════════════

    private function readServerProperties(Service $service): array
    {
        try {
            $raw    = $this->pterodactyl->readServerFile($this->identifier($service), self::PROPERTY_FILE);
            $parsed = $this->parseProperties($raw);
        } catch (\Throwable) {
            $parsed = []; // El archivo aún no existe (servidor recién instalado)
        }

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

            [$key, $value]          = explode('=', $line, 2);
            $properties[trim($key)] = trim($value);
        }

        return $properties;
    }

    private function buildPropertiesFile(array $properties): string
    {
        $lines = [
            '# Minecraft server properties',
            '# Managed by ROKE Industries',
            '# ' . now()->toDateTimeString(),
        ];

        foreach ($properties as $key => $value) {
            $lines[] = "{$key}={$value}";
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function castPropertyValue(string $key, mixed $value): mixed
    {
        return match ($key) {
            'max_players', 'spawn_protection' => (int) $value,
            'white_list', 'online_mode',
            'allow_flight', 'pvp'             => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default                           => (string) $value,
        };
    }

    private function serializePropertyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PRIVATE — Helpers
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Normaliza el tag de una imagen Docker para comparaciones exactas.
     * "ghcr.io/pterodactyl/yolks:java_21" → "java_21"
     * "java_21" → "java_21"
     */
    private function normalizeDockerTag(string $image): string
    {
        if (str_contains($image, ':')) {
            return strtolower(substr($image, strrpos($image, ':') + 1));
        }

        return strtolower($image);
    }

    /**
     * Loga un warning si el tag solicitado no está en la lista de imágenes disponibles.
     */
    private function warnIfTagUnavailable(string $tag): void
    {
        $available = config('minecraft.available_yolks_tags', []);

        if (! in_array($tag, $available, true)) {
            Log::warning("Pterodactyl Yolks: el tag '{$tag}' no está en la lista de imágenes disponibles", [
                'tag'       => $tag,
                'available' => $available,
            ]);
        }
    }

    /**
     * Construye los defaults del egg como array env_variable => default_value.
     */
    private function buildEggDefaults(array $eggVars): array
    {
        return collect($eggVars)
            ->mapWithKeys(function (array $varData): array {
                $attrs  = $varData['attributes'] ?? [];
                $envKey = $attrs['env_variable'] ?? null;
                return $envKey ? [$envKey => (string) ($attrs['default_value'] ?? '')] : [];
            })
            ->all();
    }

    /**
     * Determina la variable de entorno correcta para la versión del juego en este egg.
     */
    private function resolveVersionVariable(array $eggVars, string $default): string
    {
        $aliases     = config('minecraft.pterodactyl.version_variable_aliases', []);
        $eggVarNames = collect($eggVars)
            ->pluck('attributes.env_variable')
            ->filter()
            ->values();

        foreach ($aliases as $alias) {
            if ($alias && $eggVarNames->contains($alias)) {
                return $alias;
            }
        }

        // Cualquier variable que contenga "VERSION" en su nombre
        $found = $eggVarNames->first(fn($v) => str_contains(strtoupper($v), 'VERSION'));

        return $found ?? $default;
    }

    private function pterodactylServerSnapshot(Service $service): array
    {
        if (! $service->pterodactyl_server_id) {
            return [];
        }

        try {
            $server     = $this->pterodactyl->getServer($service->pterodactyl_server_id);
            $attributes = $server['attributes'] ?? [];

            return [
                'docker_image'    => $attributes['container']['image']           ?? null,
                'startup_command' => $attributes['container']['startup_command'] ?? null,
                'environment'     => $attributes['container']['environment']     ?? [],
                'egg_id'          => $attributes['egg']                          ?? null,
                'nest_id'         => $attributes['nest']                         ?? null,
            ];
        } catch (\Throwable $e) {
            Log::debug('pterodactylServerSnapshot: no se pudo obtener snapshot del servidor', [
                'service_id' => $service->id,
                'error'      => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function resolveSoftwareName(Service $service, string $software): string
    {
        $mcName = config("minecraft.software.{$software}.name");
        if ($mcName) {
            return $mcName;
        }

        $option = collect($this->runtimeOptions->softwareOptions($service))
            ->firstWhere('id', $software);

        if (! empty($option['name'])) {
            return $option['name'];
        }

        return ucfirst($software);
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
            throw new RuntimeException(
                "El servicio #{$service->id} no tiene un identificador de Pterodactyl asignado."
            );
        }

        return $identifier;
    }
}
