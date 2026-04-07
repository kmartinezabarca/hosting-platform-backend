<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlogPostResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'content' => $this->content,
            'image' => $this->image ? asset('storage/' . $this->image) : null,
            'readTime' => $this->read_time,
            'isFeatured' => $this->is_featured,
            'publishedAt' => $this->published_at ? $this->published_at->format('Y-m-d H:i:s') : null,
            'category' => new BlogCategoryResource($this->whenLoaded('category')),
            'authorName' => $this->author_name,
            'author' => new UserResource($this->whenLoaded('author')),
            'createdAt' => $this->created_at->format('Y-m-d H:i:s'),
            'updatedAt' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
