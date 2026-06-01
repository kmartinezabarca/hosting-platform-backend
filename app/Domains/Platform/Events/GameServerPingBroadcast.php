<?php

namespace App\Domains\Platform\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitido en tiempo real por el canal privado `game-server.{serviceUuid}` (Reverb)
 * cada vez que CollectGameServerPings o el endpoint ping-now obtienen un resultado nuevo.
 *
 * El frontend escucha `.ping.updated` y actualiza el HUD sin hacer polling HTTP.
 */
class GameServerPingBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $serviceUuid,
        public readonly ?int   $pingMs,
        public readonly bool   $isOnline,
        public readonly ?int   $players,
        public readonly array  $playerSample = [],
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("game-server.{$this->serviceUuid}")];
    }

    public function broadcastAs(): string
    {
        return 'ping.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'ping_ms'    => $this->pingMs,
            'is_online'  => $this->isOnline,
            'players'    => $this->players,
            'player_sample' => $this->playerSample,
            'sampled_at' => now()->toISOString(),
        ];
    }
}
