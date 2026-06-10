<?php

namespace App\Domains\Platform\Notifications;

use App\Domains\Platform\Models\SslCertificate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SslExpiryAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly SslCertificate $cert,
        public readonly int            $daysRemaining,
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
        $urgency = $this->daysRemaining <= 7 ? '⚠️ URGENTE: ' : '';

        return (new MailMessage)
            ->subject("{$urgency}Certificado SSL de {$this->cert->domain} vence en {$this->daysRemaining} días")
            ->view('emails.notification', [
                'notifiable'   => $notifiable,
                'title'        => "{$urgency}SSL próximo a vencer",
                'subtitle'     => "Quedan {$this->daysRemaining} días",
                'intro'        => "El certificado SSL de **{$this->cert->domain}** vence el "
                                . ($this->cert->valid_until?->format('d/m/Y') ?? 'fecha desconocida')
                                . ". Si tienes activada la renovación automática (Cloudflare/Coolify) esto se resolverá solo.",
                'detailsTitle' => 'Detalles del certificado',
                'details'      => [
                    'Dominio'          => $this->cert->domain,
                    'Emisor'           => $this->cert->issuer ?? 'Desconocido',
                    'Vencimiento'      => $this->cert->valid_until?->format('d/m/Y H:i') ?? '—',
                    'Días restantes'   => $this->daysRemaining,
                    'Renovación auto.' => $this->cert->auto_renew ? 'Activada' : 'Desactivada',
                ],
                'actionUrl'  => '/client/services/' . $this->cert->service?->uuid,
                'actionText' => 'Ver servicio',
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'target'         => 'client',
            'type'           => 'ssl_expiry_alert',
            'cert_id'        => $this->cert->uuid,
            'domain'         => $this->cert->domain,
            'days'           => $this->daysRemaining,
            'expires_at'     => $this->cert->valid_until?->toDateString(),
            'service_id'     => $this->cert->service?->uuid,
            'title'          => "SSL próximo a vencer — {$this->daysRemaining} días",
            'message'        => "El certificado SSL de {$this->cert->domain} vence en {$this->daysRemaining} días.",
            'action_url'     => '/client/services/' . $this->cert->service?->uuid,
            'action_text'    => 'Ver servicio',
            'icon'           => 'shield-off',
            'color'          => $this->daysRemaining <= 7 ? 'danger' : 'warning',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable) + [
            'timestamp' => now()->toISOString(),
        ]);
    }
}
