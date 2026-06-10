<?php

namespace App\Domains\Platform\Notifications;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;

class ServiceNotification extends Notification
{
    use Queueable;

    public function __construct(public array $notificationData) {}

    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];

        // Correo opt-in por notificación: solo se envía si quien la crea pasa
        // '_email' => true. Así las notificaciones in-app (la mayoría) no generan
        // correo, y solo los avisos urgentes sí. Se envía de forma síncrona para
        // no depender de un worker de cola.
        if (! empty($this->notificationData['_email'])) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $d     = $this->notificationData;
        $title = $d['title'] ?? 'Notificación';

        return (new MailMessage)
            ->subject($title . ' - Roke Industries')
            ->view('emails.notification', [
                'notifiable'   => $notifiable,
                'title'        => $title,
                'subtitle'     => $d['email_subtitle'] ?? 'Información de tu cuenta',
                'intro'        => $d['message'] ?? '',
                'details'      => $d['email_details'] ?? [],
                'detailsTitle' => $d['email_details_title'] ?? 'Detalles',
                'actionUrl'    => $d['action_url'] ?? null,
                'actionText'   => $d['action_text'] ?? 'Ver detalles',
            ]);
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
