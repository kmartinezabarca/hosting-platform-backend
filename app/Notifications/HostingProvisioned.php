<?php

namespace App\Notifications;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HostingProvisioned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Service $service) {}

    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];

        if ($notifiable->email_notifications ?? true) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $details = $this->service->connection_details ?? [];
        $domain = $details['domain'] ?? $this->service->domain ?? 'No configurado';

        return (new MailMessage)
            ->subject("Tu hosting {$this->service->name} está listo - Roke Industries")
            ->view('emails.notification', [
                'notifiable' => $notifiable,
                'title' => 'Tu hosting está listo',
                'subtitle' => 'Datos de acceso a Web Hosting',
                'intro' => "El servicio '{$this->service->name}' fue aprovisionado correctamente.",
                'detailsTitle' => 'Datos de acceso',
                'details' => [
                    'Dominio' => $domain,
                    'URL'     => $details['fqdn'] ?? null,
                    'Panel'   => $details['panel_url'] ?? config('coolify.base_url'),
                ],
                'noticeTitle' => 'Tu sitio está listo',
                'notice' => 'Tu aplicación ha sido creada en Coolify. Puedes acceder al panel para gestionar tu sitio y configurar despliegues.',
                'actionUrl'  => $details['panel_url'] ?? config('coolify.base_url'),
                'actionText' => 'Abrir panel de hosting',
            ]);
    }

    public function toArray(object $notifiable): array
    {
        $details = $this->service->connection_details ?? [];

        return [
            'type' => 'hosting.provisioned',
            'title' => 'Hosting listo',
            'message' => "Tu servicio '{$this->service->name}' está listo.",
            'service_id' => $this->service->uuid,
            'domain' => $details['domain'] ?? $this->service->domain,
            'panel_url' => $details['panel_url'] ?? config('coolify.base_url'),
            'action_url' => '/client/services/' . $this->service->uuid,
            'action_text' => 'Ver servicio',
            'icon' => 'server',
            'color' => 'success',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage(array_merge($this->toArray($notifiable), [
            'timestamp' => now()->toISOString(),
        ]));
    }
}
