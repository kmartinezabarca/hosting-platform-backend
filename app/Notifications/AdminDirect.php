<?php

namespace App\Notifications;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class AdminDirect extends Notification implements ShouldQueue
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

    // AdminDirect is exclusively for admin-to-admin messages.
    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.notifications')];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->notificationData['title'] ?? 'Mensaje directo de Roke Industries';

        return (new MailMessage)
            ->subject($title)
            ->view('emails.notification', [
                'notifiable' => $notifiable,
                'title' => $title,
                'subtitle' => 'Mensaje personal de Roke Industries',
                'intro' => $this->notificationData['message'] ?? 'Tienes un nuevo mensaje personal.',
                'detailsTitle' => 'Detalles del mensaje',
                'details' => [
                    'Enviado por' => $this->notificationData['sent_by'] ?? 'Roke Industries',
                    'Tipo' => $this->notificationData['notification_type'] ?? $this->notificationData['type'] ?? 'Notificación',
                ],
                'actionUrl' => $this->notificationData['action_url'] ?? null,
                'actionText' => $this->notificationData['action_text'] ?? 'Ver detalles',
                'footerNote' => 'Este mensaje personal fue enviado desde Roke Industries. Si no lo reconoces, contacta a soporte.',
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
            'is_personal'       => true,
            'target'            => 'admin',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage(array_merge($this->toArray($notifiable), [
            'timestamp' => now()->toISOString(),
        ]));
    }
}
