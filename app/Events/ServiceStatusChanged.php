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

class ServiceStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $service;
    public $oldStatus;
    public $newStatus;

    /**
     * Create a new event instance.
     */
    public function __construct(Service $service, string $oldStatus, string $newStatus)
    {
        $this->service = $service;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
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
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'message' => $this->getStatusMessage(),
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'service.status.changed';
    }

    /**
     * Get user-friendly status message.
     */
    private function getStatusMessage(): string
    {
        switch ($this->newStatus) {
            case 'active':
                return "Tu servicio '{$this->service->name}' está ahora activo y listo para usar.";
            case 'suspended':
                return "Tu servicio '{$this->service->name}' ha sido suspendido.";
            case 'terminated':
                return "Tu servicio '{$this->service->name}' ha sido terminado.";
            case 'pending':
                return "Tu servicio '{$this->service->name}' está siendo configurado.";
            case 'failed':
                return "Hubo un problema configurando tu servicio '{$this->service->name}'. Contacta soporte.";
            default:
                return "El estado de tu servicio '{$this->service->name}' ha cambiado.";
        }
    }
}

