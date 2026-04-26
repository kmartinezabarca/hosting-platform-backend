<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketReplyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'message'     => $this->message,
            'is_internal' => (bool) $this->is_internal,
            'attachments' => $this->attachments,
            'user'        => $this->whenLoaded('user', fn() => [
                'id'         => $this->user->id,
                'name'       => trim("{$this->user->first_name} {$this->user->last_name}"),
                'avatar_url' => $this->user->avatar_full_url ?? null,
                'role'       => $this->user->role ?? null,
            ]),
            'created_at'  => optional($this->created_at)->toIso8601String(),
            'updated_at'  => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
