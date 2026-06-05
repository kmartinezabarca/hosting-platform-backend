<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlogSubscriptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
            return [
                'uuid' => $this->uuid,
                'email' => $this->email,
                'isActive' => $this->is_active,
                'createdAt' => $this->created_at->format('Y-m-d H:i:s'),
            ];
    }
}
