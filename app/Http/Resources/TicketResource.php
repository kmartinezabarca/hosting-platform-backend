<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'          => $this->uuid,
            'ticket_number' => $this->ticket_number,
            'subject'       => $this->subject,
            'status'        => $this->status,
            'priority'      => $this->priority,
            'department'    => $this->department,
            'user'          => new UserResource($this->whenLoaded('user')),
            'assigned_to'   => new UserResource($this->whenLoaded('assignedTo')),
            'replies_count' => $this->whenCounted('replies'),
            'last_reply_at' => optional($this->replies->last()?->created_at ?? null)?->toIso8601String(),
            'closed_at'     => optional($this->closed_at)->toIso8601String(),
            'created_at'    => optional($this->created_at)->toIso8601String(),
            'updated_at'    => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
