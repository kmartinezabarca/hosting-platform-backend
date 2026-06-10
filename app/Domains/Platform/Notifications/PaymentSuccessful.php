<?php

namespace App\Domains\Platform\Notifications;

use App\Domains\Platform\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class PaymentSuccessful extends Notification implements ShouldQueue
{
    use Queueable;

    protected $transaction;

    /**
     * Create a new notification instance.
     */
    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
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
        $amount = number_format((float) ($this->transaction->amount ?? 0), 2);
        $currency = strtoupper($this->transaction->currency ?? 'MXN');

        return (new MailMessage)
            ->subject('Pago procesado exitosamente - Roke Industries')
            ->view('emails.notification', [
                'notifiable' => $notifiable,
                'title' => 'Pago procesado exitosamente',
                'subtitle' => 'Confirmación de pago',
                'intro' => 'Tu pago ha sido procesado correctamente y tu cuenta fue actualizada.',
                'detailsTitle' => 'Detalles del pago',
                'details' => [
                    'Monto' => "\${$amount} {$currency}",
                    'Descripción' => $this->transaction->description,
                    'ID de transacción' => $this->transaction->uuid,
                    'Estado' => $this->transaction->status ?? 'completed',
                ],
                'actionUrl' => '/client/transactions/' . $this->transaction->uuid,
                'actionText' => 'Ver transacción',
            ]);
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'target' => 'client',
            'type' => 'payment_successful',
            'transaction_id' => $this->transaction->uuid,
            'amount' => $this->transaction->amount,
            'currency' => $this->transaction->currency,
            'title' => 'Pago exitoso',
            'message' => "Tu pago de {$this->transaction->amount} {$this->transaction->currency} ha sido procesado exitosamente.",
            'action_url' => '/dashboard/billing/transactions/' . $this->transaction->uuid,
            'action_text' => 'Ver transacción',
            'icon' => 'credit-card',
            'color' => 'success',
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'type' => 'payment_successful',
            'transaction_id' => $this->transaction->uuid,
            'amount' => $this->transaction->amount,
            'currency' => $this->transaction->currency,
            'title' => 'Pago exitoso',
            'message' => "Tu pago de {$this->transaction->amount} {$this->transaction->currency} ha sido procesado exitosamente.",
            'action_url' => '/dashboard/billing/transactions/' . $this->transaction->uuid,
            'action_text' => 'Ver transacción',
            'icon' => 'credit-card',
            'color' => 'success',
            'timestamp' => now()->toISOString(),
        ]);
    }
}
