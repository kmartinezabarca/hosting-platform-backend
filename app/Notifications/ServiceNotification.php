<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;

class ServiceNotification extends Notification
{
    use Queueable;

    public function __construct(public array $notificationData) {}

    // Guarda en DB y emite por WS
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    // Lo que se guarda en la tabla notifications
    public function toArray(object $notifiable): array
    {
        return [
            'title'     => $this->notificationData['title']   ?? 'Notificación',
            'message'   => $this->notificationData['message'] ?? '',
            'type'      => $this->notificationData['type']    ?? 'info',
            'data'      => $this->notificationData['data']    ?? [],
            'timestamp' => now()->toISOString(),
        ];
    }

    // Lo que se envía por WS (puedes reutilizar lo de arriba)
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
