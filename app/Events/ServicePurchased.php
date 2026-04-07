<?php

namespace App\Events;

use App\Models\Service;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow; // para pruebas sin cola
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ServicePurchased implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public Service $service;
    public float $purchaseAmount;

    public function __construct(Service $service, float $purchaseAmount)
    {
        // nos aseguramos de tener el usuario y su uuid
        $this->service = $service->loadMissing(['user:id,uuid,name']);
        $this->purchaseAmount = $purchaseAmount;

        Log::info('ServicePurchased::__construct', [
            'service_id'  => $this->service->id,
            'service_uuid'=> $this->service->uuid ?? null,
            'user_loaded' => $this->service->relationLoaded('user'),
            'user_id'     => $this->service->user?->id,
            'user_uuid'   => $this->service->user?->uuid,
            'amount'      => $this->purchaseAmount,
        ]);
    }

    public function broadcastOn(): array
    {
        $user = $this->service->user; // puede ser null si algo falló

        // log de los canales que vamos a usar
        Log::info('ServicePurchased::broadcastOn', [
            'service_id' => $this->service->id,
            'user_uuid'  => $user?->uuid,
            'channels'   => [
                $user?->uuid ? 'private-user.'.$user->uuid : '(omitido: sin uuid)',
                'private-admin.services',
            ],
        ]);

        // si no hay uuid, emite solo a admin.services para no construir "user."
        $channels = [ new PrivateChannel('admin.services') ];
        if ($user?->uuid) {
            $channels[] = new PrivateChannel('user.'.$user->uuid);
        }
        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'service.purchased';
    }

    public function broadcastWith(): array
    {
        $payload = [
            'service_id'      => $this->service->uuid ?? $this->service->id,
            'service_name'    => $this->service->name,
            'purchase_amount' => $this->purchaseAmount,
            'message'         => "¡Gracias por tu compra! Tu servicio '{$this->service->name}' ha sido adquirido exitosamente.",
            'timestamp'       => now()->toISOString(),
        ];

        Log::info('ServicePurchased::broadcastWith', $payload);

        return $payload;
    }
}
