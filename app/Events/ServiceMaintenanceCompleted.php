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

class ServiceMaintenanceCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $service;
    public $maintenanceNotes;

    /**
     * Create a new event instance.
     */
    public function __construct(Service $service, string $maintenanceNotes = '')
    {
        $this->service = $service;
        $this->maintenanceNotes = $maintenanceNotes;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->service->user->uuid),
            new PrivateChannel('admin.maintenance'),
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
            'maintenance_notes' => $this->maintenanceNotes,
            'message' => "El mantenimiento de tu servicio '{$this->service->name}' ha sido completado exitosamente.",
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'service.maintenance.completed';
    }
}

