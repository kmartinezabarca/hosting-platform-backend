<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class PaymentNotification extends Notification implements ShouldQueue
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
        return ['database', 'broadcast'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
                    ->subject($this->notificationData['title'])
                    ->line($this->notificationData['message'])
                    ->action('Ver Detalles', url('/dashboard/payments'))
                    ->line('Gracias por usar nuestra plataforma.');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->notificationData['title'],
            'message' => $this->notificationData['message'],
            'type' => $this->notificationData['type'],
            'data' => $this->notificationData['data'] ?? [],
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'title' => $this->notificationData['title'],
            'message' => $this->notificationData['message'],
            'type' => $this->notificationData['type'],
            'data' => $this->notificationData['data'] ?? [],
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn()
    {
        return ['user.' . $this->notifiable->id];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs()
    {
        return 'payment.notification.received';
    }
}

