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

class ServicePurchased implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $service;
    public $purchaseAmount;

    /**
     * Create a new event instance.
     */
    public function __construct(Service $service, float $purchaseAmount)
    {
        $this->service = $service;
        $this->purchaseAmount = $purchaseAmount;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->service->user_id),
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
            'purchase_amount' => $this->purchaseAmount,
            'message' => "¡Gracias por tu compra! Tu servicio '{$this->service->name}' ha sido adquirido exitosamente y está siendo configurado.",
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'service.purchased';
    }
}

