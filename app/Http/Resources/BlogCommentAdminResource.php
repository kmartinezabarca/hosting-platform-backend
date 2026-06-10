<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlogCommentAdminResource extends JsonResource
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
            'authorEmail' => $this->author_email,
            'content' => $this->content,
            'isApproved' => $this->is_approved,
            'ipAddress' => $this->ip_address,
            'post' => $this->whenLoaded('post', fn () => [
                'uuid' => $this->post->uuid,
                'title' => $this->post->title,
                'slug' => $this->post->slug,
            ]),
            'createdAt' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
