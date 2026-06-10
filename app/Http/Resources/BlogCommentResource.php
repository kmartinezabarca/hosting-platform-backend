<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlogCommentResource extends JsonResource
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
            'authorName' => $this->author_name,
            'content' => $this->content,
            'isApproved' => $this->is_approved,
            'createdAt' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
