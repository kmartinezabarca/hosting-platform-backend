<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillingCycleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->name,
            'months' => $this->months,
            'discount_percentage' => $this->discount_percentage ? (float) $this->discount_percentage : null,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'created_at' => $this->when($this->shouldIncludeTimestamp($request), fn () => $this->created_at?->toIso8601String()),
            'updated_at' => $this->when($this->shouldIncludeTimestamp($request), fn () => $this->updated_at?->toIso8601String()),
        ];
    }

    private function shouldIncludeTimestamp(Request $request): bool
    {
        return $request->has('include_timestamps') || $request->is('api/admin/*');
    }
}
