<?php

namespace App\Domains\Platform\Console\Commands;

use App\Domains\Platform\Models\GameSoftwareVersion;
use App\Domains\Platform\Services\Minecraft\MinecraftVersionService;
use App\Domains\Platform\Services\SoftwareVersionService;
use Illuminate\Console\Command;

/**
 * Gestión del catálogo interno de versiones de software de servidores de juego.
 *
 * Uso:
 *   php artisan game:versions list [--software=paper]         # lista versiones en BD
 *   php artisan game:versions add paper 1.21.5 [--recommended] [--notes="..."]
 *   php artisan game:versions enable  paper 1.19.4           # activa una versión
 *   php artisan game:versions disable paper 1.8.8            # desactiva (sin borrar)
 *   php artisan game:versions recommend paper 1.21.5         # marca como recomendada
 *   php artisan game:versions remove  paper 1.8.8            # elimina de BD
 *   php artisan game:versions refresh [--software=paper]     # invalida caché y recarga
 */
class ManageGameVersions extends Command
{
    protected $signature = 'game:versions
                            {action        : list | add | enable | disable | recommend | remove | refresh}
                            {software?     : Identificador del software (paper, vanilla, fabric…)}
                            {version?      : Cadena de versión (1.21.4, latest…)}
                            {--recommended : Marcar como versión recomendada al agregar}
                            {--notes=      : Notas opcionales para la versión}';

    protected $description = 'Gestiona el catálogo interno de versiones de software de servidores de juego';

    public function __construct(
        private readonly SoftwareVersionService  $versionService,
        private readonly MinecraftVersionService $minecraftVersionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $action   = strtolower($this->argument('action'));
        $software = strtolower(trim((string) $this->argument('software')));
        $version  = trim((string) $this->argument('version'));

        return match ($action) {
            'list'      => $this->actionList($software),
            'add'       => $this->actionAdd($software, $version),
            'enable'    => $this->actionSetActive($software, $version, true),
            'disable'   => $this->actionSetActive($software, $version, false),
            'recommend' => $this->actionRecommend($software, $version),
            'remove'    => $this->actionRemove($software, $version),
            'refresh'   => $this->actionRefresh($software),
            default     => $this->invalidAction($action),
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Acciones
    // ─────────────────────────────────────────────────────────────────────────

    private function actionList(string $software): int
    {
        $query = GameSoftwareVersion::ordered();

        if ($software) {
            $query->forSoftware($software);
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->warn('No hay versiones registradas' . ($software ? " para '{$software}'" : '') . '.');
            return self::SUCCESS;
        }

        $this->table(
            ['Software', 'Versión', 'Activa', 'Recomendada', 'Sort', 'Notas'],
            $rows->map(fn ($r) => [
                $r->software_identifier,
                $r->version,
                $r->is_active      ? '<fg=green>✓</>' : '<fg=red>✗</>',
                $r->is_recommended ? '<fg=yellow>★</>' : '-',
                $r->sort_order,
                substr($r->notes ?? '', 0, 60),
            ])->all(),
        );

        $this->line('');
        $this->info("Total: {$rows->count()} versión(es).");

        return self::SUCCESS;
    }

    private function actionAdd(string $software, string $version): int
    {
        if (!$this->requireArgs($software, $version)) {
            return self::FAILURE;
        }

        if (GameSoftwareVersion::forSoftware($software)->where('version', $version)->exists()) {
            $this->error("La versión '{$version}' ya existe para '{$software}'. Usa 'enable' para reactivarla.");
            return self::FAILURE;
        }

        // Nueva versión al tope: sort_order = max_actual + 1
        $sortOrder = GameSoftwareVersion::nextSortOrder($software);

        // Si se marca como recomendada, quitar la marca de la anterior
        if ($this->option('recommended')) {
            GameSoftwareVersion::forSoftware($software)
                ->where('is_recommended', true)
                ->update(['is_recommended' => false]);
        }

        GameSoftwareVersion::create([
            'software_identifier' => $software,
            'version'             => $version,
            'is_active'           => true,
            'is_recommended'      => (bool) $this->option('recommended'),
            'sort_order'          => $sortOrder,
            'notes'               => $this->option('notes') ?: null,
        ]);

        $this->invalidateCaches($software);

        $this->info("Versión '{$version}' agregada para '{$software}' (sort_order={$sortOrder}).");

        if ($this->option('recommended')) {
            $this->line("  → Marcada como versión recomendada.");
        }

        return self::SUCCESS;
    }

    private function actionSetActive(string $software, string $version, bool $active): int
    {
        if (!$this->requireArgs($software, $version)) {
            return self::FAILURE;
        }

        $record = GameSoftwareVersion::forSoftware($software)
            ->where('version', $version)
            ->first();

        if (!$record) {
            $this->error("Versión '{$version}' no encontrada para '{$software}'.");
            return self::FAILURE;
        }

        $record->update(['is_active' => $active]);
        $this->invalidateCaches($software);

        $label = $active ? '<fg=green>activada</>' : '<fg=yellow>desactivada</>';
        $this->line("Versión '{$version}' de '{$software}' {$label}.");

        return self::SUCCESS;
    }

    private function actionRecommend(string $software, string $version): int
    {
        if (!$this->requireArgs($software, $version)) {
            return self::FAILURE;
        }

        $record = GameSoftwareVersion::forSoftware($software)
            ->where('version', $version)
            ->first();

        if (!$record) {
            $this->error("Versión '{$version}' no encontrada para '{$software}'.");
            return self::FAILURE;
        }

        // Quitar recomendación anterior
        GameSoftwareVersion::forSoftware($software)
            ->where('is_recommended', true)
            ->update(['is_recommended' => false]);

        $record->update(['is_recommended' => true, 'is_active' => true]);
        $this->invalidateCaches($software);

        $this->info("'{$version}' ahora es la versión recomendada de '{$software}'.");

        return self::SUCCESS;
    }

    private function actionRemove(string $software, string $version): int
    {
        if (!$this->requireArgs($software, $version)) {
            return self::FAILURE;
        }

        $record = GameSoftwareVersion::forSoftware($software)
            ->where('version', $version)
            ->first();

        if (!$record) {
            $this->error("Versión '{$version}' no encontrada para '{$software}'.");
            return self::FAILURE;
        }

        if (!$this->confirm("¿Eliminar permanentemente '{$version}' de '{$software}'? (Preferible usar 'disable')")) {
            $this->line('Operación cancelada.');
            return self::SUCCESS;
        }

        $record->delete();
        $this->invalidateCaches($software);

        $this->warn("Versión '{$version}' de '{$software}' eliminada de la BD.");

        return self::SUCCESS;
    }

    private function actionRefresh(string $software): int
    {
        $ids = $software
            ? [$software]
            : SoftwareVersionService::knownIdentifiers();

        foreach ($ids as $id) {
            $result = $this->versionService->refreshVersions($id);
            $count  = count($result['versions']);
            $this->line("  <fg=green>✓</> {$id}  →  {$count} versión(es) desde BD.");
        }

        // Invalidar también la caché de opciones de MinecraftVersionService
        $this->minecraftVersionService->invalidateCache();
        $this->info('Caché de versiones actualizada.');

        return self::SUCCESS;
    }

    private function invalidAction(string $action): int
    {
        $this->error("Acción desconocida: '{$action}'.");
        $this->line('Acciones válidas: list | add | enable | disable | recommend | remove | refresh');
        return self::FAILURE;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function requireArgs(string $software, string $version): bool
    {
        if (!$software) {
            $this->error('Debes especificar el software (p. ej. paper, vanilla, fabric…).');
            return false;
        }

        if (!$version) {
            $this->error('Debes especificar la versión (p. ej. 1.21.4).');
            return false;
        }

        return true;
    }

    private function invalidateCaches(string $software): void
    {
        $this->versionService->invalidateCache($software);
        $this->minecraftVersionService->invalidateCache();
    }
}
