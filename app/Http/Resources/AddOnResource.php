<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AddOnResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'         => $this->id,
            'uuid'       => $this->uuid,
            'slug'       => $this->slug,
            'name'       => $this->name,
            'description' => $this->description,
            'price'      => (float) $this->price,
            'currency'   => $this->currency,
            'is_active'  => $this->is_active,
            'metadata'   => $this->metadata ?? (object)[],
            'plans'      => $this->whenLoaded('plans', fn() => $this->plans->map(fn($p) => [
                'id' => $p->id,
                'uuid' => $p->uuid ?? null,
                'name' => $p->name
            ])),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
