<?php

namespace App\Domains\Platform\Notifications;

use App\Domains\Platform\Models\Receipt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class InvoiceReady extends Notification implements ShouldQueue
{
    use Queueable;

    protected $invoice;

    /**
     * Create a new notification instance.
     */
    public function __construct(Receipt $invoice)
    {
        $this->invoice = $invoice;
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
        $amount = number_format((float) ($this->invoice->total ?? 0), 2);
        $currency = strtoupper($this->invoice->currency ?? 'MXN');

        return (new MailMessage)
            ->subject("Comprobante de pago #{$this->invoice->invoice_number} disponible - Roke Industries")
            ->view('emails.notification', [
                'notifiable' => $notifiable,
                'title' => 'Comprobante de pago disponible',
                'subtitle' => 'Tu comprobante de pago está listo',
                'intro' => "Tu comprobante #{$this->invoice->invoice_number} está listo para revisar y descargar desde tu panel.",
                'detailsTitle' => 'Detalles del comprobante',
                'details' => [
                    'Folio' => $this->invoice->invoice_number,
                    'Total' => "\${$amount} {$currency}",
                    'Fecha de vencimiento' => $this->invoice->due_date?->format('d/m/Y') ?? 'No disponible',
                    'Estado' => $this->invoice->status_text ?? 'Enviada',
                ],
                'actionUrl' => '/client/invoices/' . $this->invoice->uuid,
                'actionText' => 'Ver comprobante',
            ]);
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'invoice_ready',
            'invoice_id' => $this->invoice->uuid,
            'invoice_number' => $this->invoice->invoice_number,
            'amount' => $this->invoice->total,
            'currency' => $this->invoice->currency,
            'due_date' => $this->invoice->due_date?->toDateString(),
            'title' => 'Nuevo comprobante de pago',
            'message' => "Tu comprobante #{$this->invoice->invoice_number} por {$this->invoice->total} {$this->invoice->currency} está listo.",
            'action_url' => '/client/invoices/' . $this->invoice->uuid,
            'action_text' => 'Ver comprobante',
            'icon' => 'document-text',
            'color' => 'info',
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'type' => 'invoice_ready',
            'invoice_id' => $this->invoice->uuid,
            'invoice_number' => $this->invoice->invoice_number,
            'amount' => $this->invoice->total,
            'currency' => $this->invoice->currency,
            'due_date' => $this->invoice->due_date?->toDateString(),
            'title' => 'Nuevo comprobante de pago',
            'message' => "Tu comprobante #{$this->invoice->invoice_number} por {$this->invoice->total} {$this->invoice->currency} está listo.",
            'action_url' => '/client/invoices/' . $this->invoice->uuid,
            'action_text' => 'Ver comprobante',
            'icon' => 'document-text',
            'color' => 'info',
            'timestamp' => now()->toISOString(),
        ]);
    }
}
