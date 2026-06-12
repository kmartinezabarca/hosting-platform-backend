<?php

namespace App\Domains\Platform\Compute\Events;

use App\Domains\Platform\Compute\Models\Deployment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Chunk de log de build en vivo → private-deployment.{uuid}.
 * El frontend lo agrega a la consola; si pierde la conexión, repagina
 * con GET /v2/deployments/{uuid}/logs?after_seq=.
 */
class DeploymentLogChunk implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Deployment $deployment,
        public readonly int $seq,
        public readonly string $stream,
        public readonly string $chunk,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('deployment.' . $this->deployment->uuid);
    }

    public function broadcastAs(): string
    {
        return 'log.chunk';
    }

    public function broadcastWith(): array
    {
        return [
            'seq'    => $this->seq,
            'stream' => $this->stream,
            'chunk'  => $this->chunk,
        ];
    }
}
