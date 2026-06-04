<?php

namespace App\Domains\Platform\Events;

use App\Domains\Platform\Models\Ticket;
use App\Domains\Platform\Models\TicketReply;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketReplied implements ShouldBroadcastNow
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
            // Canal del ticket en modo presence. El frontend usa .join() para
            // saber quién está online y recibir mensajes, typing y receipts
            // emitidos por el backend en tiempo real.
            new PresenceChannel('ticket.' . $this->ticket->uuid),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        $this->reply->load('user:id,uuid,first_name,last_name,email,avatar_url,role');

        $isFromStaff = $this->reply->isFromStaff();
        $author      = $this->reply->user;
        $authorName  = $author?->full_name ?: $author?->email;

        return [
            'ticket_id'      => $this->ticket->uuid,
            'ticket_uuid'    => $this->ticket->uuid,
            'ticket_subject' => $this->ticket->subject,
            'reply_id'       => $this->reply->id,
            'reply_uuid'     => $this->reply->uuid,
            'reply_message'  => $this->reply->message,
            'reply_by'       => $authorName ?? ($isFromStaff ? 'Soporte' : 'Cliente'),
            'is_from_staff'  => $isFromStaff,

            // Objeto reply completo para renderizar el mensaje en tiempo real
            // sin tener que hacer un refetch (incluye adjuntos con URL).
            'reply' => [
                'id'          => $this->reply->id,
                'uuid'        => $this->reply->uuid,
                'ticket_id'   => $this->ticket->id,
                'ticket_uuid' => $this->ticket->uuid,
                'user_id'     => $this->reply->user_id,
                'message'     => $this->reply->message,
                'attachments' => $this->reply->attachments, // accessor añade `url`
                'is_internal' => (bool) $this->reply->is_internal,
                'is_from_staff' => $isFromStaff,
                'delivered_at' => $this->reply->delivered_at?->toISOString(),
                'read_at'     => $this->reply->read_at?->toISOString(),
                'created_at'  => $this->reply->created_at?->toISOString(),
                'user'        => $author ? [
                    'id'         => $author->id,
                    'uuid'       => $author->uuid,
                    'name'       => $authorName,
                    'first_name' => $author->first_name,
                    'last_name'  => $author->last_name,
                    'email'      => $author->email,
                    'avatar_url' => $author->avatar_url,
                    'role'       => $author->role ?: ($isFromStaff ? 'admin' : 'client'),
                    'is_staff'   => $isFromStaff,
                ] : null,
            ],

            'message'        => $isFromStaff
                ? "El equipo de soporte ha respondido a tu ticket: {$this->ticket->subject}"
                : "Nueva respuesta del cliente en el ticket: {$this->ticket->subject}",
            'timestamp'      => now()->toISOString(),
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'ticket.replied';
    }
}
