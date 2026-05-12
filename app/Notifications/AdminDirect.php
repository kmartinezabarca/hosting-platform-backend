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
        $mail = (new MailMessage)
            ->subject($this->notificationData['title'])
            ->greeting('¡Hola ' . $notifiable->name . '!')
            ->line($this->notificationData['message'])
            ->line('Este mensaje personal fue enviado por: ' . $this->notificationData['sent_by']);

        if (!empty($this->notificationData['action_url'])) {
            $mail->action(
                $this->notificationData['action_text'] ?? 'Ver Más',
                $this->notificationData['action_url']
            );
        }

        return $mail;
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
