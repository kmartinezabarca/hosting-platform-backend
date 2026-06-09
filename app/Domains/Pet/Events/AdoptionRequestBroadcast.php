<?php

namespace App\Domains\Pet\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitido en tiempo real por el canal privado `rp-owner.{ownerId}` (Reverb)
 * cuando alguien solicita adoptar una de las publicaciones del usuario.
 *
 * El frontend escucha `.adoption.request` y muestra el aviso al instante.
 */
class AdoptionRequestBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $ownerId,
        public readonly string $listingId,
        public readonly string $listingSlug,
        public readonly string $petName,
        public readonly string $title,
        public readonly string $body,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("rp-owner.{$this->ownerId}")];
    }

    public function broadcastAs(): string
    {
        return 'adoption.request';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'type'        => 'adoption_request',
            'listingId'   => $this->listingId,
            'listingSlug' => $this->listingSlug,
            'petName'     => $this->petName,
            'title'       => $this->title,
            'body'        => $this->body,
            'sentAt'      => now()->toISOString(),
        ];
    }
}
