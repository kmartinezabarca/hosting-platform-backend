<?php

namespace App\Domains\Platform\Notifications;

use App\Domains\Platform\Models\Domain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DomainExpiryAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Domain $domain,
        public readonly int    $daysRemaining,
    ) {}

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
        $urgency = match (true) {
            $this->daysRemaining <= 7  => '⚠️ URGENTE: ',
            $this->daysRemaining <= 15 => '⚠️ Importante: ',
            default                    => '',
        };

        return (new MailMessage)
            ->subject("{$urgency}Tu dominio {$this->domain->domain_name} vence en {$this->daysRemaining} días")
            ->view('emails.notification', [
                'notifiable'   => $notifiable,
                'title'        => "{$urgency}Dominio próximo a vencer",
                'subtitle'     => "Quedan {$this->daysRemaining} días",
                'intro'        => "Tu dominio **{$this->domain->domain_name}** vence el "
                                . $this->domain->expiration_date->format('d/m/Y')
                                . ". Renuévalo para evitar perderlo.",
                'detailsTitle' => 'Detalles del dominio',
                'details'      => [
                    'Dominio'            => $this->domain->domain_name,
                    'Vencimiento'        => $this->domain->expiration_date->format('d/m/Y'),
                    'Días restantes'     => $this->daysRemaining,
                    'Renovación auto.'   => $this->domain->auto_renew ? 'Activada' : 'Desactivada',
                ],
                'actionUrl'  => '/client/domains/' . $this->domain->uuid,
                'actionText' => 'Renovar dominio',
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'domain_expiry_alert',
            'domain_id'  => $this->domain->uuid,
            'domain'     => $this->domain->domain_name,
            'days'       => $this->daysRemaining,
            'expires_at' => $this->domain->expiration_date->toDateString(),
            'title'      => "Dominio próximo a vencer — {$this->daysRemaining} días",
            'message'    => "Tu dominio {$this->domain->domain_name} vence en {$this->daysRemaining} días ({$this->domain->expiration_date->format('d/m/Y')}).",
            'action_url' => '/client/domains/' . $this->domain->uuid,
            'action_text'=> 'Renovar',
            'icon'       => 'calendar-x',
            'color'      => $this->daysRemaining <= 7 ? 'danger' : ($this->daysRemaining <= 15 ? 'warning' : 'info'),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable) + [
            'timestamp' => now()->toISOString(),
        ]);
    }
}
