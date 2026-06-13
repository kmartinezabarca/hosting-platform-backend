<?php

namespace App\Domains\Pet\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitido cuando alguien reporta una publicación (adopción / comunidad / reseña).
 * Lo escuchan SOLO los admins de Pet por el canal privado `rp-admin.moderation`
 * para mostrar el aviso al instante y refrescar la cola/badge de moderación, sin
 * esperar al polling.
 */
class PetModerationReportBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $type,        // 'adoption' | 'post' | 'review'
        public readonly string $id,
        public readonly string $title,
        public readonly string $reason,      // spam | inappropriate | scam | false | other
        public readonly int    $openReports,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('rp-admin.moderation')];
    }

    public function broadcastAs(): string
    {
        return 'report.created';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'type'        => $this->type,
            'id'          => $this->id,
            'title'       => $this->title,
            'reason'      => $this->reason,
            'openReports' => $this->openReports,
            'sentAt'      => now()->toISOString(),
        ];
    }
}
