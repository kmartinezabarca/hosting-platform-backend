<?php

namespace App\Domains\Platform\Notifications;

use App\Domains\Platform\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class ServiceActivated extends Notification implements ShouldQueue
{
    use Queueable;

    protected $service;

    /**
     * Create a new notification instance.
     */
    public function __construct(Service $service)
    {
        $this->service = $service;
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
        return (new MailMessage)
            ->subject('Tu servicio está activo - Roke Industries')
            ->view('emails.notification', [
                'notifiable' => $notifiable,
                'title' => 'Tu servicio está activo',
                'subtitle' => 'Servicio listo para usar',
                'intro' => "Tu servicio '{$this->service->name}' está activo y listo para usar.",
                'detailsTitle' => 'Detalles del servicio',
                'details' => [
                    'Servicio' => $this->service->name,
                    'Estado' => $this->service->status ?? 'active',
                    'Fecha de activación' => $this->service->activated_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i'),
                ],
                'actionUrl' => '/client/services/' . $this->service->uuid,
                'actionText' => 'Ver servicio',
            ]);
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'target' => 'client',
            'type' => 'service_activated',
            'service_id' => $this->service->uuid,
            'service_name' => $this->service->name,
            'title' => 'Servicio activado',
            'message' => "Tu servicio '{$this->service->name}' está ahora activo y listo para usar.",
            'action_url' => '/dashboard/services/' . $this->service->uuid,
            'action_text' => 'Ver servicio',
            'icon' => 'check-circle',
            'color' => 'success',
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'type' => 'service_activated',
            'service_id' => $this->service->uuid,
            'service_name' => $this->service->name,
            'title' => 'Servicio activado',
            'message' => "Tu servicio '{$this->service->name}' está ahora activo y listo para usar.",
            'action_url' => '/dashboard/services/' . $this->service->uuid,
            'action_text' => 'Ver servicio',
            'icon' => 'check-circle',
            'color' => 'success',
            'timestamp' => now()->toISOString(),
        ]);
    }
}
