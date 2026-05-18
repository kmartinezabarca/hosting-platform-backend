<?php

namespace App\Events;

use App\Models\Receipt;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InvoiceGenerated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @var Receipt */
    public $invoice;

    /**
     * Create a new event instance.
     * @param Receipt $invoice  Comprobante de pago generado.
     */
    public function __construct(Receipt $invoice)
    {
        $this->invoice = $invoice;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->invoice->user->uuid),
            new PrivateChannel('admin.invoices'),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'invoice_id'     => $this->invoice->uuid,
            'invoice_number' => $this->invoice->invoice_number,
            'amount'         => $this->invoice->total,
            'currency'       => $this->invoice->currency,
            'due_date'       => $this->invoice->due_date?->toDateString(),
            'status'         => $this->invoice->status,
            'message'        => $this->getInvoiceMessage(),
            'timestamp'      => now()->toISOString(),
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'invoice.generated';
    }

    /**
     * Get user-friendly invoice message.
     */
    private function getInvoiceMessage(): string
    {
        return "Nuevo comprobante #{$this->invoice->invoice_number} por {$this->invoice->total} {$this->invoice->currency} está disponible.";
    }
}

