<?php

namespace App\Events;

use App\Models\Service;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServiceReady implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $service;
    public $accessDetails;

    /**
     * Create a new event instance.
     */
    public function __construct(Service $service, array $accessDetails = [])
    {
        $this->service = $service;
        $this->accessDetails = $accessDetails;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->service->user->uuid),
            new PrivateChannel('admin.services'),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'service_id' => $this->service->uuid,
            'service_name' => $this->service->name,
            'access_details' => $this->accessDetails,
            'message' => "¡Tu servicio '{$this->service->name}' está listo para usar! Puedes acceder a él desde tu panel de control.",
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'service.ready';
    }
}

