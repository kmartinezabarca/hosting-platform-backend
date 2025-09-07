<?php

namespace App\Notifications;

use App\Models\Invoice;
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
    public function __construct(Invoice $invoice)
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
        return (new MailMessage)
            ->subject('Nueva Factura Disponible')
            ->greeting('¡Hola ' . $notifiable->name . '!')
            ->line("Tu factura #{$this->invoice->invoice_number} está lista.")
            ->line("Monto: {$this->invoice->total_amount} {$this->invoice->currency}")
            ->line("Fecha de vencimiento: {$this->invoice->due_date->format('d/m/Y')}")
            ->action('Ver Factura', url('/dashboard/billing/invoices/' . $this->invoice->uuid))
            ->line('Puedes descargar tu factura desde tu panel de control.');
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
            'amount' => $this->invoice->total_amount,
            'currency' => $this->invoice->currency,
            'due_date' => $this->invoice->due_date->toDateString(),
            'title' => 'Nueva Factura',
            'message' => "Tu factura #{$this->invoice->invoice_number} por {$this->invoice->total_amount} {$this->invoice->currency} está lista.",
            'action_url' => '/dashboard/billing/invoices/' . $this->invoice->uuid,
            'action_text' => 'Ver Factura',
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
            'amount' => $this->invoice->total_amount,
            'currency' => $this->invoice->currency,
            'due_date' => $this->invoice->due_date->toDateString(),
            'title' => 'Nueva Factura',
            'message' => "Tu factura #{$this->invoice->invoice_number} por {$this->invoice->total_amount} {$this->invoice->currency} está lista.",
            'action_url' => '/dashboard/billing/invoices/' . $this->invoice->uuid,
            'action_text' => 'Ver Factura',
            'icon' => 'document-text',
            'color' => 'info',
            'timestamp' => now()->toISOString(),
        ]);
    }
}

