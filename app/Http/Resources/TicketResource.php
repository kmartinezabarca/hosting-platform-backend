<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\TicketReplyResource;

class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'uuid'          => $this->uuid,
            'ticket_number' => $this->ticket_number,
            'subject'       => $this->subject,
            'description'   => $this->description,
            'status'        => $this->status,
            'status_label'  => $this->status_label,
            'priority'      => $this->priority,
            'priority_label'=> $this->priority_label,
            'category'      => $this->category,
            'category_label'=> $this->category_label,
            'department'    => $this->department,
            'user'          => new UserResource($this->whenLoaded('user')),
            'assigned_to'   => new UserResource($this->whenLoaded('assignedTo')),
            'service'       => $this->whenLoaded('service', fn() => [
                'id'   => $this->service->id,
                'uuid' => $this->service->uuid ?? null,
                'name' => $this->service->name ?? null,
            ]),
            // Full reply list — only present on showTicket (detail view)
            'replies'       => TicketReplyResource::collection($this->whenLoaded('replies')),
            'replies_count' => $this->whenCounted('replies'),
            // Use the already-loaded last_reply_at column (avoids N+1)
            'last_reply_at' => optional($this->last_reply_at)->toIso8601String(),
            'closed_at'     => optional($this->closed_at)->toIso8601String(),
            'resolved_at'   => optional($this->resolved_at)->toIso8601String(),
            'created_at'    => optional($this->created_at)->toIso8601String(),
            'updated_at'    => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
