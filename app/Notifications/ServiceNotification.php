<?php

namespace App\Notifications;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;

class ServiceNotification extends Notification
{
    use Queueable;

    public function __construct(public array $notificationData) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    // Channel is pre-computed by the listener and passed as '_channel'.
    // This avoids needing the notifiable parameter, which the base class doesn't support.
    public function broadcastOn(): array
    {
        $channel = $this->notificationData['_channel'] ?? null;

        return $channel ? [new PrivateChannel($channel)] : [];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title'     => $this->notificationData['title']   ?? 'Notificación',
            'message'   => $this->notificationData['message'] ?? '',
            'type'      => $this->notificationData['type']    ?? 'info',
            'target'    => $this->notificationData['target']  ?? 'client',
            'data'      => $this->notificationData['data']    ?? [],
            'timestamp' => now()->toISOString(),
            // _channel is intentionally excluded — routing only, not persisted
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
