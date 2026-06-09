<?php

namespace App\Domains\Pet\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitido en tiempo real por el canal privado `rp-owner.{ownerId}` (Reverb)
 * cuando escanean a una mascota perdida o alguien reporta haberla encontrado.
 *
 * El frontend del dueño escucha `.pet.scanned` y muestra el aviso al instante,
 * sin depender de push ni de refrescar la app.
 */
class PetScanBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string  $ownerId,
        public readonly string  $type,      // 'lost_pet_scan' | 'pet_found_report'
        public readonly string  $petId,
        public readonly string  $petSlug,
        public readonly string  $petName,
        public readonly string  $title,
        public readonly string  $body,
        public readonly ?string $city = null,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("rp-owner.{$this->ownerId}")];
    }

    public function broadcastAs(): string
    {
        return 'pet.scanned';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'type'    => $this->type,
            'petId'   => $this->petId,
            'petSlug' => $this->petSlug,
            'petName' => $this->petName,
            'title'   => $this->title,
            'body'    => $this->body,
            'city'    => $this->city,
            'sentAt'  => now()->toISOString(),
        ];
    }
}
