<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class AdminDirect extends Notification implements ShouldQueue
{
    use Queueable;

    protected $notificationData;

    /**
     * Create a new notification instance.
     */
    public function __construct(array $notificationData)
    {
        $this->notificationData = $notificationData;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];
        
        // Agregar email si el usuario tiene habilitadas las notificaciones por email
        if ($notifiable->email_notifications ?? true) {
            $channels[] = 'mail';
        }
        
        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     */
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

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->notificationData['type'],
            'title' => $this->notificationData['title'],
            'message' => $this->notificationData['message'],
            'notification_type' => $this->notificationData['notification_type'],
            'action_url' => $this->notificationData['action_url'] ?? null,
            'action_text' => $this->notificationData['action_text'] ?? null,
            'icon' => $this->notificationData['icon'],
            'color' => $this->notificationData['color'],
            'sent_by' => $this->notificationData['sent_by'],
            'is_personal' => true,
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'type' => $this->notificationData['type'],
            'title' => $this->notificationData['title'],
            'message' => $this->notificationData['message'],
            'notification_type' => $this->notificationData['notification_type'],
            'action_url' => $this->notificationData['action_url'] ?? null,
            'action_text' => $this->notificationData['action_text'] ?? null,
            'icon' => $this->notificationData['icon'],
            'color' => $this->notificationData['color'],
            'sent_by' => $this->notificationData['sent_by'],
            'is_personal' => true,
            'timestamp' => now()->toISOString(),
        ]);
    }
}

