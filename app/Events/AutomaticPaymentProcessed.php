<?php

namespace App\Events;

use App\Models\Transaction;
use App\Models\Invoice;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AutomaticPaymentProcessed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $transaction;
    public $invoice;

    /**
     * Create a new event instance.
     */
    public function __construct(Transaction $transaction, Invoice $invoice = null)
    {
        $this->transaction = $transaction;
        $this->invoice = $invoice;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->transaction->user_id),
            new PrivateChannel('admin.payments'),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'transaction_id' => $this->transaction->uuid,
            'invoice_id' => $this->invoice?->uuid,
            'invoice_number' => $this->invoice?->invoice_number,
            'amount' => $this->transaction->amount,
            'currency' => $this->transaction->currency,
            'status' => $this->transaction->status,
            'message' => $this->getPaymentMessage(),
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'payment.automatic.processed';
    }

    /**
     * Get user-friendly payment message.
     */
    private function getPaymentMessage(): string
    {
        $invoiceText = $this->invoice ? " para la factura #{$this->invoice->invoice_number}" : "";
        
        if ($this->transaction->status === 'completed') {
            return "Tu pago automático de {$this->transaction->amount} {$this->transaction->currency}{$invoiceText} ha sido procesado exitosamente.";
        } elseif ($this->transaction->status === 'failed') {
            return "Tu pago automático de {$this->transaction->amount} {$this->transaction->currency}{$invoiceText} no pudo ser procesado. Por favor, verifica tu método de pago.";
        } else {
            return "Tu pago automático de {$this->transaction->amount} {$this->transaction->currency}{$invoiceText} está siendo procesado.";
        }
    }
}

