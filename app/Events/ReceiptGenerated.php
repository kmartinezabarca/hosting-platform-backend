<?php

namespace App\Events;

use App\Models\Receipt;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReceiptGenerated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Receipt $receipt;

    public function __construct(Receipt $receipt)
    {
        $this->receipt = $receipt;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->receipt->user->uuid),
            new PrivateChannel('admin.invoices'),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'receipt_id'     => $this->receipt->uuid,
            'invoice_number' => $this->receipt->invoice_number,
            'amount'         => $this->receipt->total,
            'currency'       => $this->receipt->currency,
            'due_date'       => $this->receipt->due_date?->toDateString(),
            'status'         => $this->receipt->status,
            'message'        => "Tu comprobante #{$this->receipt->invoice_number} por {$this->receipt->total} {$this->receipt->currency} está disponible.",
            'timestamp'      => now()->toISOString(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'receipt.generated';
    }
}
