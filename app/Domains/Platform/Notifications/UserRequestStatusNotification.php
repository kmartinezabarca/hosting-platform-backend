<?php

namespace App\Domains\Platform\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifies a user that their request (documentation / API access / KYC) was
 * approved or rejected by staff. Delivered in-app (database) and over the
 * websocket (broadcast), matching the platform's generic notification pattern.
 */
class UserRequestStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $status,   // approved | rejected
        public readonly string $kind,
        public readonly ?string $message = null,
        public readonly array $data = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        $approved = $this->status === 'approved';

        return [
            'target'    => 'client',
            'title'     => $approved ? 'Solicitud aprobada' : 'Solicitud rechazada',
            'message'   => $this->message ?? ($approved
                ? 'Tu solicitud ha sido aprobada.'
                : 'Tu solicitud ha sido rechazada.'),
            'type'      => 'request.' . $this->status,
            'data'      => array_merge(['kind' => $this->kind], $this->data),
            'timestamp' => now()->toISOString(),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
