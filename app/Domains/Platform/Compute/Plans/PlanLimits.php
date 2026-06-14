<?php

namespace App\Domains\Platform\Compute\Plans;

use App\Domains\Platform\Compute\Models\ComputePlan;
use App\Domains\Platform\Compute\Models\Team;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Enforcement de límites por plan. La fuente primaria es el catálogo compute
 * en DB; config('compute.plans') queda como fallback para entornos sin migrar.
 */
class PlanLimits
{
    /**
     * Límites efectivos del equipo (cae a `free` si el tier no está configurado).
     *
     * @return array{max_resources: int, ram_mb_max: int, max_members: int}
     */
    public function forTeam(Team $team): array
    {
        $tier = $team->plan_tier?->value ?? 'free';
        $default = ['max_resources' => 1, 'ram_mb_max' => 512, 'max_members' => 1];
        $limit = ($this->dbLimitsForTier($tier) ?? $this->configLimitsForTier($tier) ?? $default) + $default;

        // El tope de RAM del plan nunca puede exceder la cota absoluta de spec.
        $limit['ram_mb_max'] = min($limit['ram_mb_max'], (int) config('compute.limits.ram_mb_max', 4096));

        return $limit;
    }

    /**
     * Snapshot de uso vs. cupo, para exponer en la API (p.ej. "2/2 recursos").
     *
     * @return array{plan: string, resources_used: int, max_resources: int, ram_mb_max: int, members_used: int, max_members: int}
     */
    public function usage(Team $team): array
    {
        $limit = $this->forTeam($team);

        return [
            'plan'           => $team->plan_tier?->value ?? 'free',
            'resources_used' => $team->activeResourceCount(),
            'max_resources'  => $limit['max_resources'],
            'ram_mb_max'     => $limit['ram_mb_max'],
            'members_used'   => $team->members()->count(),
            'max_members'    => $limit['max_members'],
        ];
    }

    /**
     * Valida que el equipo pueda sumar un miembro más.
     *
     * @return string|null Mensaje de error (422) o null si está permitido.
     */
    public function checkCanAddMember(Team $team): ?string
    {
        $limit = $this->forTeam($team);
        $tier  = $team->plan_tier?->value ?? 'free';

        if ($team->members()->count() >= $limit['max_members']) {
            return $limit['max_members'] <= 1
                ? "Tu plan «{$tier}» no incluye miembros de equipo. Mejora tu plan para invitar colaboradores."
                : "Alcanzaste el límite de {$limit['max_members']} miembros de tu plan «{$tier}».";
        }

        return null;
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

    /**
     * @return array{max_resources?: int, ram_mb_max?: int, max_members?: int}|null
     */
    private function dbLimitsForTier(string $tier): ?array
    {
        try {
            if (! Schema::hasTable('compute_plan_catalog_entries')) {
                return null;
            }

            $plan = ComputePlan::query()
                ->compute()
                ->where('is_active', true)
                ->where('tier', $tier)
                ->first();

            if (! $plan) {
                return null;
            }

            $limits = [];
            foreach (['max_resources', 'ram_mb_max', 'max_members'] as $key) {
                if ($plan->{$key} !== null) {
                    $limits[$key] = (int) $plan->{$key};
                }
            }

            return $limits === [] ? null : $limits;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{max_resources?: int, ram_mb_max?: int, max_members?: int}|null
     */
    private function configLimitsForTier(string $tier): ?array
    {
        $plans = config('compute.plans', []);

        return $plans[$tier] ?? $plans['free'] ?? null;
    }
}
