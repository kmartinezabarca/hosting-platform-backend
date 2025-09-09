<?php

namespace App\Events;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketReplied implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $ticket;
    public $reply;

    /**
     * Create a new event instance.
     */
    public function __construct(Ticket $ticket, TicketReply $reply)
    {
        $this->ticket = $ticket;
        $this->reply = $reply;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->ticket->user->uuid),
            new PrivateChannel('admin.tickets'),
            new PrivateChannel('ticket.' . $this->ticket->uuid),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->ticket->uuid,
            'ticket_subject' => $this->ticket->subject,
            'reply_id' => $this->reply->id,
            'reply_message' => $this->reply->message,
            'reply_by' => $this->reply->user ? $this->reply->user->name : 'Soporte',
            'is_from_admin' => $this->reply->is_from_admin,
            'message' => $this->getReplyMessage(),
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'ticket.replied';
    }

    /**
     * Get user-friendly reply message.
     */
    private function getReplyMessage(): string
    {
        if ($this->reply->is_from_admin) {
            return "El equipo de soporte ha respondido a tu ticket: {$this->ticket->subject}";
        } else {
            return "Nueva respuesta del cliente en el ticket: {$this->ticket->subject}";
        }
    }
}

