<?php

namespace App\Domains\Pet\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitido por el canal privado `rp-owner.{ownerId}` (Reverb) cuando el admin
 * envía un consejo / notificación de engagement al dueño. El frontend escucha
 * `.tip.received` (mismo shape que `.pet.scanned`) para mostrar el toast y
 * refrescar la campanita al instante, sin depender de push ni de recargar.
 */
class PetTipBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string  $ownerId,
        public readonly string  $title,
        public readonly string  $body,
        public readonly ?string $url = null,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("rp-owner.{$this->ownerId}")];
    }

    public function broadcastAs(): string
    {
        return 'tip.received';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'type'   => 'tip',
            'title'  => $this->title,
            'body'   => $this->body,
            'url'    => $this->url,
            'sentAt' => now()->toISOString(),
        ];
    }
}
