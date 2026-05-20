<?php

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a client marks staff replies as read.
 * Broadcast on the admin channel so that admin chat views can update
 * read-receipt checkmarks (✓✓) in real time without polling.
 */
class TicketRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Ticket $ticket) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.tickets')];
    }

    public function broadcastAs(): string
    {
        return 'ticket.read';
    }

    public function broadcastWith(): array
    {
        return [
            'ticket_id'   => $this->ticket->id,
            'ticket_uuid' => $this->ticket->uuid,
        ];
    }
}
