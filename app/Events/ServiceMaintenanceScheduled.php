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

class ServiceMaintenanceScheduled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $service;
    public $maintenanceStart;
    public $maintenanceEnd;
    public $description;

    /**
     * Create a new event instance.
     */
    public function __construct(Service $service, string $maintenanceStart, string $maintenanceEnd, string $description = '')
    {
        $this->service = $service;
        $this->maintenanceStart = $maintenanceStart;
        $this->maintenanceEnd = $maintenanceEnd;
        $this->description = $description;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->service->user_id),
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
            'maintenance_start' => $this->maintenanceStart,
            'maintenance_end' => $this->maintenanceEnd,
            'description' => $this->description,
            'message' => "Mantenimiento programado para tu servicio '{$this->service->name}' desde {$this->maintenanceStart} hasta {$this->maintenanceEnd}.",
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'service.maintenance.scheduled';
    }
}

