<?php

namespace App\Domains\Platform\Compute\Orchestrator;

use App\Domains\Platform\Compute\Models\Orchestration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runner de sagas: ejecuta los pasos en orden, persiste el estado tras cada
 * uno y se re-encola con delay cuando un paso queda pendiente (polling).
 *
 * Un paso que lanza excepción marca la saga como fallida y dispara
 * Flow::onFailure — no hay retry implícito de pasos (los pasos son
 * idempotentes: re-ejecutar la saga manualmente es seguro).
 */
class RunOrchestration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public readonly int $orchestrationId)
    {
    }

    public function handle(): void
    {
        $orchestration = Orchestration::find($this->orchestrationId);

        if (! $orchestration || $orchestration->isFinished()) {
            return;
        }

        // Corta sagas desbocadas (un AwaitDeployment que jamás termina).
        $orchestration->increment('attempts');
        if ($orchestration->attempts > (int) config('compute.max_orchestration_attempts', 150)) {
            $this->fail($orchestration, new \RuntimeException(
                'Orquestación abortada: superó el máximo de ejecuciones.'
            ));

            return;
        }

        $flow  = FlowRegistry::resolve($orchestration->flow);
        $steps = $orchestration->steps;

        foreach ($steps as $index => $stepState) {
            if ($stepState['status'] === 'done') {
                continue;
            }

            $steps[$index]['status']     = 'running';
            $steps[$index]['started_at'] ??= now()->toIso8601String();
            $orchestration->update(['state' => class_basename($stepState['step']), 'steps' => $steps]);

            try {
                /** @var Step $step */
                $step   = app($stepState['step']);
                $result = $step->execute($orchestration->refresh());
            } catch (\Throwable $e) {
                $steps[$index]['status']      = 'failed';
                $steps[$index]['finished_at'] = now()->toIso8601String();
                $steps[$index]['error']       = mb_substr($e->getMessage(), 0, 1000);
                $orchestration->update(['steps' => $steps]);

                $this->fail($orchestration, $e);

                return;
            }

            if (! $result->completed) {
                // Mismo paso, otra pasada después del delay.
                $steps[$index]['status'] = 'pending';
                $orchestration->update(['steps' => $steps]);

                self::dispatch($orchestration->id)
                    ->onQueue($flow->queue())
                    ->delay(now()->addSeconds($result->retryAfterSeconds));

                return;
            }

            $steps[$index]['status']      = 'done';
            $steps[$index]['finished_at'] = now()->toIso8601String();
            $orchestration->update(['steps' => $steps]);
        }

        $orchestration->update(['completed_at' => now(), 'state' => null]);
        $flow->onSuccess($orchestration->refresh());
    }

    private function fail(Orchestration $orchestration, \Throwable $e): void
    {
        $orchestration->update([
            'failed_at'  => now(),
            'last_error' => mb_substr($e->getMessage(), 0, 2000),
        ]);

        Log::error('Orquestación fallida', [
            'orchestration' => $orchestration->uuid,
            'flow'          => $orchestration->flow,
            'state'         => $orchestration->state,
            'error'         => $e->getMessage(),
        ]);

        FlowRegistry::resolve($orchestration->flow)->onFailure($orchestration->refresh(), $e);
    }
}
