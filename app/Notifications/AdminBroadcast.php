<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class AdminBroadcast extends Notification implements ShouldQueue
{
    use Queueable;

    protected $notificationData;

    public function __construct(array $notificationData)
    {
        $this->notificationData = $notificationData;
    }

    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];

        if ($notifiable->email_notifications ?? true) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    // No override of broadcastOn() — falls back to $notifiable->receivesBroadcastNotificationsOn()
    // which returns 'user.{uuid}', the correct client channel.

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->notificationData['title'] ?? 'Notificación de Roke Industries';

        return (new MailMessage)
            ->subject($title)
            ->view('emails.notification', [
                'notifiable' => $notifiable,
                'title' => $title,
                'subtitle' => 'Mensaje de Roke Industries',
                'intro' => $this->notificationData['message'] ?? 'Tienes una nueva notificación.',
                'detailsTitle' => 'Detalles del mensaje',
                'details' => [
                    'Enviado por' => $this->notificationData['sent_by'] ?? 'Roke Industries',
                    'Tipo' => $this->notificationData['notification_type'] ?? $this->notificationData['type'] ?? 'Notificación',
                ],
                'actionUrl' => $this->notificationData['action_url'] ?? null,
                'actionText' => $this->notificationData['action_text'] ?? 'Ver detalles',
                'footerNote' => 'Este mensaje fue enviado desde Roke Industries. Si tienes dudas, contacta a soporte.',
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'              => $this->notificationData['type'],
            'title'             => $this->notificationData['title'],
            'message'           => $this->notificationData['message'],
            'notification_type' => $this->notificationData['notification_type'],
            'action_url'        => $this->notificationData['action_url'] ?? null,
            'action_text'       => $this->notificationData['action_text'] ?? null,
            'icon'              => $this->notificationData['icon'],
            'color'             => $this->notificationData['color'],
            'sent_by'           => $this->notificationData['sent_by'],
            'target'            => 'client',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage(array_merge($this->toArray($notifiable), [
            'timestamp' => now()->toISOString(),
        ]));
    }
}
