<?php

namespace App\Domains\Platform\Ai;

use App\Domains\Platform\Ai\Tools\DiagnoseFailure;
use App\Domains\Platform\Ai\Tools\GetDeploymentLogs;
use App\Domains\Platform\Ai\Tools\GetResourceStatus;
use App\Domains\Platform\Ai\Tools\ListDeployments;
use App\Domains\Platform\Ai\Tools\ListProjects;
use App\Domains\Platform\Ai\Tools\Tool;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Catálogo de herramientas del agente. v1 = tier `read` exclusivamente; los
 * tiers safe_write/destructive llegan con el gate de confirmación (mes 2).
 */
class ToolRegistry
{
    /** @var class-string<Tool>[] */
    private const TOOLS = [
        ListProjects::class,
        GetResourceStatus::class,
        ListDeployments::class,
        GetDeploymentLogs::class,
        DiagnoseFailure::class,
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
        $tool = collect($this->tools())->first(fn (Tool $t) => $t->name() === $name);

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

    /** @return Tool[] */
    private function tools(): array
    {
        return array_map(fn (string $class) => app($class), self::TOOLS);
    }
}
