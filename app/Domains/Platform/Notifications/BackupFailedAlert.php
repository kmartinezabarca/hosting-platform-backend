<?php

namespace App\Domains\Platform\Notifications;

use App\Domains\Platform\Models\Backup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notificación enviada al admin cuando un backup falla 2 veces consecutivas.
 */
class BackupFailedAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Backup $backup,
        public readonly int    $consecutiveFails,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $serviceName = $this->backup->service?->name ?? '(sin servicio)';
        $userName    = $this->backup->user?->full_name ?? '(sin usuario)';

        return (new MailMessage)
            ->subject("⚠️ Backup fallido {$this->consecutiveFails} veces consecutivas — {$serviceName}")
            ->view('emails.notification', [
                'notifiable'   => $notifiable,
                'title'        => 'Alerta: Backup fallido',
                'subtitle'     => "{$this->consecutiveFails} fallos consecutivos",
                'intro'        => "El backup de tipo **{$this->backup->type}** ha fallado **{$this->consecutiveFails} veces consecutivas**. Requiere revisión.",
                'detailsTitle' => 'Detalles',
                'details'      => [
                    'Servicio'    => $serviceName,
                    'Cliente'     => $userName,
                    'Tipo'        => $this->backup->type,
                    'Último error'=> $this->backup->error ?? 'Sin mensaje de error',
                    'Fecha'       => $this->backup->completed_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i'),
                ],
                'actionUrl'  => '/admin/backups',
                'actionText' => 'Ver backups',
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'               => 'backup_failed_alert',
            'backup_id'          => $this->backup->uuid,
            'backup_type'        => $this->backup->type,
            'service_id'         => $this->backup->service?->uuid,
            'consecutive_fails'  => $this->consecutiveFails,
            'error'              => $this->backup->error,
            'title'              => "Backup fallido ({$this->consecutiveFails}x consecutivos)",
            'message'            => "El backup de {$this->backup->type} ha fallado {$this->consecutiveFails} veces consecutivas. Revisar configuración del NAS o proveedor.",
            'action_url'         => '/admin/backups',
            'action_text'        => 'Ver backups',
            'icon'               => 'alert-triangle',
            'color'              => 'danger',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable) + [
            'timestamp' => now()->toISOString(),
        ]);
    }
}
