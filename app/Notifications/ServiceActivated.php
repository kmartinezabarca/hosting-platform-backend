<?php

namespace App\Notifications;

use App\Models\Service;
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
            ->subject('¡Tu servicio está activo!')
            ->greeting('¡Hola ' . $notifiable->name . '!')
            ->line("Tu servicio '{$this->service->name}' está ahora activo y listo para usar.")
            ->line('Puedes acceder a los detalles de tu servicio desde tu panel de control.')
            ->action('Ver Servicio', url('/dashboard/services/' . $this->service->uuid))
            ->line('¡Gracias por confiar en nosotros!');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'service_activated',
            'service_id' => $this->service->uuid,
            'service_name' => $this->service->name,
            'title' => '¡Servicio Activado!',
            'message' => "Tu servicio '{$this->service->name}' está ahora activo y listo para usar.",
            'action_url' => '/dashboard/services/' . $this->service->uuid,
            'action_text' => 'Ver Servicio',
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
            'title' => '¡Servicio Activado!',
            'message' => "Tu servicio '{$this->service->name}' está ahora activo y listo para usar.",
            'action_url' => '/dashboard/services/' . $this->service->uuid,
            'action_text' => 'Ver Servicio',
            'icon' => 'check-circle',
            'color' => 'success',
            'timestamp' => now()->toISOString(),
        ]);
    }
}

