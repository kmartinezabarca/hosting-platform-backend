<?php

namespace App\Events;

use App\Models\Invoice;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InvoiceStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $invoice;
    public $oldStatus;
    public $newStatus;

    /**
     * Create a new event instance.
     */
    public function __construct(Invoice $invoice, string $oldStatus, string $newStatus)
    {
        $this->invoice = $invoice;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
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
            'invoice_id' => $this->invoice->uuid,
            'invoice_number' => $this->invoice->invoice_number,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'amount' => $this->invoice->total_amount,
            'currency' => $this->invoice->currency,
            'message' => $this->getStatusMessage(),
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'invoice.status.changed';
    }

    /**
     * Get user-friendly status message.
     */
    private function getStatusMessage(): string
    {
        switch ($this->newStatus) {
            case 'paid':
                return "Tu factura #{$this->invoice->invoice_number} ha sido pagada exitosamente.";
            case 'overdue':
                return "Tu factura #{$this->invoice->invoice_number} está vencida. Por favor realiza el pago lo antes posible.";
            case 'cancelled':
                return "Tu factura #{$this->invoice->invoice_number} ha sido cancelada.";
            case 'refunded':
                return "Tu factura #{$this->invoice->invoice_number} ha sido reembolsada.";
            default:
                return "El estado de tu factura #{$this->invoice->invoice_number} ha cambiado a {$this->newStatus}.";
        }
    }
}

