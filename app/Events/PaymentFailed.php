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

class PaymentFailed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $transaction;
    public $reason;

    /**
     * Create a new event instance.
     */
    public function __construct(Transaction $transaction, string $reason = '')
    {
        $this->transaction = $transaction;
        $this->reason = $reason;
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
            'reason' => $this->reason,
            'message' => "Tu pago de {$this->transaction->amount} {$this->transaction->currency} no pudo ser procesado. " . ($this->reason ? "Razón: {$this->reason}" : "Por favor, verifica tu método de pago e intenta nuevamente."),
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'payment.failed';
    }
}

