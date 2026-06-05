<?php

namespace App\Domains\Platform\Events;

use App\Domains\Platform\Models\TicketReply;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Receipt broadcast: when a reply transitions to delivered_at or read_at,
 * we emit this event on the presence channel of the ticket so the SENDER's
 * UI updates the checkmark (✓ delivered, ✓✓ read) in real time without
 * polling. This event is the source-of-truth persistence + reconnection-safe
 * path for the sender UI.
 */
class TicketReplyReceiptUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly TicketReply $reply,
        public readonly string $status,         // 'delivered' | 'read'
        public readonly ?int $byUserId = null,  // who triggered the receipt (the recipient)
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('ticket.' . $this->reply->ticket->uuid),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ticket.reply.receipt';
    }

    public function broadcastWith(): array
    {
        return [
            'reply_id'     => $this->reply->id,
            'reply_uuid'   => $this->reply->uuid,
            'ticket_uuid'  => $this->reply->ticket->uuid,
            'status'       => $this->status,
            'delivered_at' => optional($this->reply->delivered_at)->toISOString(),
            'read_at'      => optional($this->reply->read_at)->toISOString(),
            'by_user_id'   => $this->byUserId,
            'timestamp'    => now()->toISOString(),
        ];
    }
}
