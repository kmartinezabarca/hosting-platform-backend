<?php

namespace App\Notifications;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class PaymentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $notificationData;

    public function __construct(array $notificationData)
    {
        $this->notificationData = $notificationData;
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    // Channel is pre-computed by the listener and passed as '_channel'.
    public function broadcastOn(): array
    {
        $channel = $this->notificationData['_channel'] ?? null;

        return $channel ? [new PrivateChannel($channel)] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->notificationData['title'] ?? 'Notificación de pago';

        return (new MailMessage)
            ->subject($title . ' - Roke Industries')
            ->view('emails.notification', [
                'notifiable' => $notifiable,
                'title' => $title,
                'subtitle' => 'Información de pagos',
                'intro' => $this->notificationData['message'] ?? 'Tienes una actualización relacionada con tus pagos.',
                'detailsTitle' => 'Detalles',
                'details' => $this->notificationData['data'] ?? [],
                'actionUrl' => '/client/invoices',
                'actionText' => 'Ver pagos',
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title'     => $this->notificationData['title'],
            'message'   => $this->notificationData['message'],
            'type'      => $this->notificationData['type'],
            'target'    => $this->notificationData['target'] ?? 'client',
            'data'      => $this->notificationData['data'] ?? [],
            'timestamp' => now()->toISOString(),
            // _channel is intentionally excluded — routing only, not persisted
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
