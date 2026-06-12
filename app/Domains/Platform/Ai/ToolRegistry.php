<?php

namespace App\Domains\Platform\Ai;

use App\Domains\Platform\Ai\Tools\ApplyFix;
use App\Domains\Platform\Ai\Tools\DiagnoseFailure;
use App\Domains\Platform\Ai\Tools\GetDeploymentLogs;
use App\Domains\Platform\Ai\Tools\GetResourceStatus;
use App\Domains\Platform\Ai\Tools\ListDeployments;
use App\Domains\Platform\Ai\Tools\ListProjects;
use App\Domains\Platform\Ai\Tools\RedeployResource;
use App\Domains\Platform\Ai\Tools\RollbackDeployment;
use App\Domains\Platform\Ai\Tools\SetEnvVar;
use App\Domains\Platform\Ai\Tools\Tool;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Catálogo de herramientas del agente. Las de lectura (read) se auto-ejecutan;
 * las de escritura (WriteTool, tier safe_write/destructive) se proponen y solo
 * corren tras la confirmación del usuario — el gate vive en AgentRunner.
 */
class ToolRegistry
{
    /** @var class-string<Tool>[] */
    private const TOOLS = [
        // read
        ListProjects::class,
        GetResourceStatus::class,
        ListDeployments::class,
        GetDeploymentLogs::class,
        DiagnoseFailure::class,
        // safe_write (requieren confirmación)
        SetEnvVar::class,
        RedeployResource::class,
        RollbackDeployment::class,
        ApplyFix::class,
    ];

    /** Definiciones en formato `tools` de la Messages API. */
    public function definitions(): array
    {
        return array_map(fn (Tool $tool) => [
            'name'         => $tool->name(),
            'description'  => $tool->description(),
            'input_schema' => $tool->schema(),
        ], $this->tools());
    }

    /**
     * Ejecuta una herramienta como $user. Los errores se devuelven como
     * resultado (no excepción) para que el modelo pueda explicarlos.
     */
    public function execute(User $user, string $name, array $arguments): array
    {
        $tool = $this->find($name);

        if (! $tool) {
            return ['error' => "Herramienta desconocida: {$name}"];
        }

        try {
            return $tool->execute($user, $arguments);
        } catch (\Throwable $e) {
            Log::warning('Tool del agente falló', ['tool' => $name, 'error' => $e->getMessage()]);

            return ['error' => 'La herramienta falló al ejecutarse.'];
        }
    }

    /** Resuelve una herramienta por su nombre público (o null si no existe). */
    public function find(string $name): ?Tool
    {
        return collect($this->tools())->first(fn (Tool $t) => $t->name() === $name);
    }

    /** @return Tool[] */
    private function tools(): array
    {
        return array_map(fn (string $class) => app($class), self::TOOLS);
    }
}
