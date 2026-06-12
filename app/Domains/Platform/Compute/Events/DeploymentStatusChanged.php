<?php

namespace App\Domains\Platform\Compute\Events;

use App\Domains\Platform\Compute\Models\Deployment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambio de estado de un deployment → private-project.{uuid}, para que las
 * listas de deployments y el estado del recurso se actualicen en vivo.
 */
class DeploymentStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Deployment $deployment)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        $projectUuid = $this->deployment->resource->environment->project->uuid;

        return new PrivateChannel('project.' . $projectUuid);
    }

    public function broadcastAs(): string
    {
        return 'deployment.status';
    }

    public function broadcastWith(): array
    {
        return [
            'deployment' => $this->deployment->uuid,
            'resource'   => $this->deployment->resource->uuid,
            'status'     => $this->deployment->status->value,
            'finished_at' => $this->deployment->finished_at?->toIso8601String(),
        ];
    }
}
