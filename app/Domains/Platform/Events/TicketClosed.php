<?php

namespace App\Domains\Platform\Events;

use App\Domains\Platform\Models\Ticket;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a support ticket / chat room is closed (by either side).
 * Broadcasts on the same channels as TicketReplied so both the client and the
 * staff views update in real time without polling.
 */
class TicketClosed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public ?User $closedBy = null,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->ticket->user->uuid),
            new PrivateChannel('admin.tickets'),
            new PresenceChannel('ticket.' . $this->ticket->uuid),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ticket.closed';
    }

    public function broadcastWith(): array
    {
        return [
            'ticket_id'   => $this->ticket->id,
            'ticket_uuid' => $this->ticket->uuid,
            'status'      => $this->ticket->status,
            'closed_by'   => $this->closedBy?->full_name,
            'is_staff'    => $this->closedBy?->isStaff() ?? false,
            'message'     => 'El ticket ha sido cerrado.',
            'timestamp'   => now()->toISOString(),
        ];
    }
}
