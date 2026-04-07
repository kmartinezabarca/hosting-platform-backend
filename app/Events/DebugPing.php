<?php
namespace App\Events;

use Illuminate\Broadcasting\Channel; // OJO: Channel (público)
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class DebugPing implements ShouldBroadcastNow
{
    public function __construct(public string $msg) {}

    public function broadcastOn(): array
    {
        return [new Channel('debug')]; // canal público
    }

    public function broadcastAs(): string
    {
        return 'debug.ping';
    }

    public function broadcastWith(): array
    {
        return ['msg' => $this->msg, 'ts' => now()->toISOString()];
    }
}
