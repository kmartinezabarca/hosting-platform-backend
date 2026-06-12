<?php

namespace App\Domains\Platform\Compute\Plans;

use App\Domains\Platform\Compute\Models\Team;

/**
 * Enforcement de límites por plan (mes 2 #7). Los topes viven en
 * config('compute.plans') por tier; aquí se resuelven y se comprueban contra
 * el uso real del equipo. Una sola fuente de verdad para el conteo de recursos
 * y el tope de RAM, usada al crear recursos y para mostrar uso/cupo en la UI.
 */
class PlanLimits
{
    /**
     * Límites efectivos del equipo (cae a `free` si el tier no está configurado).
     *
     * @return array{max_resources: int, ram_mb_max: int}
     */
    public function forTeam(Team $team): array
    {
        $plans = config('compute.plans', []);
        $tier  = $team->plan_tier?->value ?? 'free';
        $limit = $plans[$tier] ?? $plans['free'] ?? ['max_resources' => 1, 'ram_mb_max' => 512];

        // El tope de RAM del plan nunca puede exceder la cota absoluta de spec.
        $limit['ram_mb_max'] = min($limit['ram_mb_max'], (int) config('compute.limits.ram_mb_max', 4096));

        return $limit;
    }

    /**
     * Snapshot de uso vs. cupo, para exponer en la API (p.ej. "2/2 recursos").
     *
     * @return array{plan: string, resources_used: int, max_resources: int, ram_mb_max: int}
     */
    public function usage(Team $team): array
    {
        $limit = $this->forTeam($team);

        return [
            'plan'           => $team->plan_tier?->value ?? 'free',
            'resources_used' => $team->activeResourceCount(),
            'max_resources'  => $limit['max_resources'],
            'ram_mb_max'     => $limit['ram_mb_max'],
        ];
    }

    /**
     * Valida que el equipo pueda crear un recurso con la RAM pedida.
     *
     * @return string|null Mensaje de error (422) o null si está permitido.
     */
    public function check(Team $team, int $ramMb): ?string
    {
        $limit = $this->forTeam($team);
        $tier  = $team->plan_tier?->value ?? 'free';

        if ($team->activeResourceCount() >= $limit['max_resources']) {
            return "Alcanzaste el límite de {$limit['max_resources']} recursos de tu plan «{$tier}». "
                . 'Mejora tu plan para crear más.';
        }

        if ($ramMb > $limit['ram_mb_max']) {
            return "Tu plan «{$tier}» permite hasta {$limit['ram_mb_max']} MB de RAM por recurso "
                . "(pediste {$ramMb} MB).";
        }

        return null;
    }
}
