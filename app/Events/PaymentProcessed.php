<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentProcessed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $transaction;

    /**
     * Create a new event instance.
     */
    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->transaction->user->uuid),
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
            'amount' => $this->transaction->amount,
            'currency' => $this->transaction->currency,
            'status' => $this->transaction->status,
            'description' => $this->transaction->description,
            'message' => $this->getPaymentMessage(),
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'payment.processed';
    }

    /**
     * Get user-friendly payment message.
     */
    private function getPaymentMessage(): string
    {
        if ($this->transaction->status === 'completed') {
            return "Tu pago de {$this->transaction->amount} {$this->transaction->currency} ha sido procesado exitosamente.";
        } elseif ($this->transaction->status === 'failed') {
            return "Tu pago de {$this->transaction->amount} {$this->transaction->currency} no pudo ser procesado. Por favor, intenta nuevamente.";
        } else {
            return "Tu pago de {$this->transaction->amount} {$this->transaction->currency} está siendo procesado.";
        }
    }
}

