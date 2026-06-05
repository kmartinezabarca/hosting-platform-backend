<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlogCategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->uuid,   // public-facing identifier (UUID)
            'name'        => $this->name,
            'slug'        => $this->slug,
            'description' => $this->description,
            'isActive'    => $this->is_active,
            'sortOrder'   => $this->sort_order,
            'postsCount'  => $this->when(isset($this->posts_count), $this->posts_count),
            'createdAt'   => $this->created_at->format('Y-m-d H:i:s'),
            'updatedAt'   => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
