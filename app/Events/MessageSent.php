<?php

namespace App\Events;

use App\Models\ChatRoom;
use App\Models\ChatMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $chatRoom;
    public $message;

    /**
     * Create a new event instance.
     */
    public function __construct(ChatRoom $chatRoom, ChatMessage $message)
    {
        $this->chatRoom = $chatRoom;
        $this->message = $message;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.' . $this->chatRoom->uuid),
            new PrivateChannel('user.' . $this->chatRoom->user_id),
            new PrivateChannel('admin.chat'),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'chat_room_id' => $this->chatRoom->uuid,
            'message' => [
                'id' => $this->message->id,
                'message' => $this->message->message,
                'type' => $this->message->type,
                'attachment_url' => $this->message->attachment_url,
                'is_from_admin' => $this->message->is_from_admin,
                'user' => $this->message->user ? [
                    'id' => $this->message->user->id,
                    'name' => $this->message->user->name,
                    'avatar' => $this->message->user->avatar,
                ] : null,
                'created_at' => $this->message->created_at->toISOString(),
            ],
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'message.sent';
    }
}

