<?php

namespace App\Notifications;

use App\Models\Transaction;
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
        return (new MailMessage)
            ->subject('Pago Procesado Exitosamente')
            ->greeting('¡Hola ' . $notifiable->name . '!')
            ->line("Tu pago de {$this->transaction->amount} {$this->transaction->currency} ha sido procesado exitosamente.")
            ->line("Descripción: {$this->transaction->description}")
            ->line("ID de transacción: {$this->transaction->uuid}")
            ->action('Ver Transacción', url('/dashboard/billing/transactions/' . $this->transaction->uuid))
            ->line('¡Gracias por tu pago!');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payment_successful',
            'transaction_id' => $this->transaction->uuid,
            'amount' => $this->transaction->amount,
            'currency' => $this->transaction->currency,
            'title' => 'Pago Exitoso',
            'message' => "Tu pago de {$this->transaction->amount} {$this->transaction->currency} ha sido procesado exitosamente.",
            'action_url' => '/dashboard/billing/transactions/' . $this->transaction->uuid,
            'action_text' => 'Ver Transacción',
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
            'title' => 'Pago Exitoso',
            'message' => "Tu pago de {$this->transaction->amount} {$this->transaction->currency} ha sido procesado exitosamente.",
            'action_url' => '/dashboard/billing/transactions/' . $this->transaction->uuid,
            'action_text' => 'Ver Transacción',
            'icon' => 'credit-card',
            'color' => 'success',
            'timestamp' => now()->toISOString(),
        ]);
    }
}

