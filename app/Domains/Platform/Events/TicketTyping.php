<?php

namespace App\Domains\Platform\Events;

use App\Domains\Platform\Models\Ticket;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Typing indicator ("escribiendo…").
 *
 * Server-driven alternative to client whispers: it broadcasts on the ticket
 * presence channel so the other party sees the indicator in real time. We use
 * ShouldBroadcastNow (no queue) because a typing signal is only useful while
 * it is fresh — it must be delivered immediately, never deferred to a worker.
 *
 * The caller dispatches it with ->toOthers() so the author never receives an
 * echo of their own typing event. The frontend should debounce start/stop and
 * auto-clear the indicator after a few seconds without a new `is_typing:true`.
 */
class TicketTyping implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly User $user,
        public readonly bool $isTyping = true,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('ticket.' . $this->ticket->uuid),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ticket.typing';
    }

    public function broadcastWith(): array
    {
        return [
            'ticket_uuid' => $this->ticket->uuid,
            'is_typing'   => $this->isTyping,
            'user' => [
                'id'       => $this->user->uuid,
                'name'     => $this->user->full_name,
                'role'     => $this->user->role,
                'is_staff' => $this->user->isAdmin(),
            ],
            'timestamp'   => now()->toISOString(),
        ];
    }
}
