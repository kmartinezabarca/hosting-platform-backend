<?php

namespace App\Domains\Platform\Console\Commands;

use App\Domains\Platform\Compute\Models\Orchestration;
use App\Domains\Platform\Compute\Orchestrator\FlowRegistry;
use App\Domains\Platform\Compute\Orchestrator\RunOrchestration;
use Illuminate\Console\Command;

/**
 * Red de seguridad del plano de cómputo: re-encola las orquestaciones VIVAS que
 * se quedaron estancadas (p. ej. el worker se cayó y el job se perdió en Redis).
 *
 * A diferencia del hosting (`provisioning:process-pending`), el cómputo avanza
 * re-encolándose a sí mismo paso a paso; sin esta red, un worker caído cuelga el
 * despliegue para siempre (justo lo que pasó con angular-crud-frontend).
 *
 * "Estancada" = sin completed_at/failed_at y sin actualizarse en --minutes (un
 * paso normal refresca updated_at cada pocos segundos). RunOrchestration es
 * idempotente y aborta solo al superar `compute.max_orchestration_attempts`,
 * así que re-encolar es seguro. Programado en app/Console/Kernel.php.
 */
class RequeueStuckOrchestrations extends Command
{
    protected $signature = 'compute:requeue-stuck-orchestrations
                            {--minutes=5 : Minutos sin avanzar para considerarla estancada}
                            {--dry-run : Solo listar, sin re-encolar}';

    protected $description = 'Re-encola orquestaciones de cómputo estancadas (recuperación tras caída del worker).';

    private const LIMIT = 50;

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $cutoff  = now()->subMinutes($minutes);

        $stuck = Orchestration::query()
            ->whereNull('completed_at')
            ->whereNull('failed_at')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->limit(self::LIMIT)
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No hay orquestaciones estancadas.');

            return self::SUCCESS;
        }

        $requeued = 0;

        foreach ($stuck as $o) {
            $this->line(" → #{$o->id} {$o->uuid} flujo={$o->flow} intentos={$o->attempts} (sin avanzar desde {$o->updated_at})");

            if ($this->option('dry-run')) {
                continue;
            }

            try {
                $queue = FlowRegistry::resolve($o->flow)->queue();
                RunOrchestration::dispatch($o->id)->onQueue($queue);
                $requeued++;
            } catch (\Throwable $e) {
                $this->error("   no se pudo re-encolar #{$o->id}: {$e->getMessage()}");
            }
        }

        $this->info(($this->option('dry-run') ? '[DRY-RUN] ' : '') . "Re-encoladas: {$requeued}/{$stuck->count()}");

        return self::SUCCESS;
    }
}
