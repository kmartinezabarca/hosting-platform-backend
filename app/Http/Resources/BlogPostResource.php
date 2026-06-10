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
            'image' => $this->image,
            'readTime' => $this->read_time,
            'likes' => (int) $this->likes,
            'views' => (int) $this->views,
            'commentsCount' => $this->whenCounted('comments'),
            'isFeatured' => $this->is_featured,
            'is_published' => $this->is_published,
            'publishedAt' => $this->published_at ? $this->published_at->format('Y-m-d H:i:s') : null,
            'category' => new BlogCategoryResource($this->whenLoaded('category')),
            'authorName' => $this->author_name,
            'author' => new UserResource($this->whenLoaded('author')),
            'createdAt' => $this->created_at->format('Y-m-d H:i:s'),
            'updatedAt' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
