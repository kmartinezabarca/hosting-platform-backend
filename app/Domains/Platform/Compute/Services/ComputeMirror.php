<?php

namespace App\Domains\Platform\Compute\Services;

use App\Domains\Platform\Compute\Enums\EnvironmentType;
use App\Domains\Platform\Compute\Enums\ResourceKind;
use App\Domains\Platform\Compute\Enums\ResourceStatus;
use App\Domains\Platform\Compute\Enums\TeamRole;
use App\Domains\Platform\Compute\Models\Resource;
use App\Domains\Platform\Compute\Models\Team;
use App\Domains\Platform\Models\Service;
use Illuminate\Support\Str;

/**
 * Espeja servicios legacy (plano de billing) al plano de cómputo para que
 * la API v2 y el agente de IA los vean. Los game servers ya se aprovisionan
 * automáticamente tras el pago (ServiceContractingService → ProvisioningService);
 * este espejo crea su Resource sin tocar ese flujo maduro.
 *
 * Idempotente: la búsqueda es por resources.service_id.
 */
class ComputeMirror
{
    /**
     * Crea/actualiza el Resource espejo de un game server. Devuelve null si
     * el servicio no es un game server aprovisionado.
     */
    public function syncGameServer(Service $service): ?Resource
    {
        if (! $service->pterodactyl_server_id) {
            return null;
        }

        $existing = Resource::where('service_id', $service->id)->first();
        if ($existing) {
            return $this->refresh($existing, $service);
        }

        $team = $this->ensurePersonalTeam($service);

        // Proyecto contenedor "Game Servers" por equipo (los game servers no
        // tienen repo ni deploys — un proyecto agrupador basta).
        $project = $team->projects()->firstOrCreate(
            ['slug' => 'game-servers'],
            ['name' => 'Game Servers'],
        );

        $environment = $project->environments()->firstOrCreate(
            ['slug' => 'production'],
            ['name' => 'Production', 'type' => EnvironmentType::Production],
        );

        $resource = $environment->resources()->create([
            'kind'       => ResourceKind::GameServer,
            'name'       => $service->name,
            'status'     => $this->mapStatus($service->status),
            'spec'       => $this->buildSpec($service),
            'service_id' => $service->id,
        ]);

        $resource->providerRefs()->create([
            'provider'      => 'pterodactyl',
            'external_id'   => (string) $service->pterodactyl_server_id,
            'external_meta' => [
                'uuid'       => $service->pterodactyl_server_uuid,
                'identifier' => data_get($service->connection_details, 'identifier'),
            ],
        ]);

        return $resource;
    }

    private function refresh(Resource $resource, Service $service): Resource
    {
        $resource->update([
            'name'   => $service->name,
            'status' => $this->mapStatus($service->status),
            'spec'   => $this->buildSpec($service),
        ]);

        return $resource;
    }

    private function buildSpec(Service $service): array
    {
        return [
            'game'        => $service->selectedEgg?->display_name ?? $service->selectedEgg?->name,
            'max_players' => $service->max_players,
            // Dirección pública de conexión — ya curada por el provisioning
            // legacy (hostname o IP:puerto), sin URLs del panel.
            'address'     => data_get($service->connection_details, 'display'),
        ];
    }

    private function mapStatus(?string $serviceStatus): ResourceStatus
    {
        return match ($serviceStatus) {
            'active'     => ResourceStatus::Running,
            'suspended'  => ResourceStatus::Suspended,
            'failed'     => ResourceStatus::Failed,
            'terminated' => ResourceStatus::Deleting,
            default      => ResourceStatus::Provisioning,
        };
    }

    /** Mismo contrato que el backfill de la semana 1. */
    private function ensurePersonalTeam(Service $service): Team
    {
        $user = $service->user;

        $team = $user->ownedTeams()->where('is_personal', true)->first();
        if ($team) {
            return $team;
        }

        $name = $user->username ?: trim((string) $user->first_name) ?: ('user-' . $user->id);
        $base = Str::slug($name) ?: 'team';
        $slug = $base;
        while (Team::where('slug', $slug)->exists()) {
            $slug = $base . '-' . Str::lower(Str::random(4));
        }

        $team = Team::create([
            'name'          => $name,
            'slug'          => $slug,
            'owner_user_id' => $user->id,
            'is_personal'   => true,
        ]);
        $team->members()->attach($user->id, ['role' => TeamRole::Owner->value]);

        return $team;
    }
}
