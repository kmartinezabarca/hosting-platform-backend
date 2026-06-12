<?php

namespace App\Domains\Platform\Compute\Orchestrator;

/**
 * Resultado de un paso: completado (avanza) o pendiente (el runner re-encola
 * el job con delay y vuelve a ejecutar EL MISMO paso — así se modela el
 * polling de builds sin bloquear workers).
 */
final class StepResult
{
    private function __construct(
        public readonly bool $completed,
        public readonly int $retryAfterSeconds,
    ) {
    }

    public static function completed(): self
    {
        return new self(true, 0);
    }

    public static function pending(?int $retryAfterSeconds = null): self
    {
        return new self(false, $retryAfterSeconds ?? (int) config('compute.deploy_poll_seconds', 8));
    }
}
