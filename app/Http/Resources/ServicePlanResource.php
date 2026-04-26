<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServicePlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'category_id' => $this->category_id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'base_price' => $this->base_price ? (float) $this->base_price : null,
            'setup_fee' => $this->setup_fee ? (float) $this->setup_fee : null,
            'is_popular' => $this->is_popular,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'specifications' => $this->specifications ?? [],
            'created_at' => $this->when($this->shouldIncludeTimestamp($request), fn () => $this->created_at?->toIso8601String()),
            'updated_at' => $this->when($this->shouldIncludeTimestamp($request), fn () => $this->updated_at?->toIso8601String()),

            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'uuid' => $this->category->uuid,
                'slug' => $this->category->slug,
                'name' => $this->category->name,
            ]),

            'features' => $this->whenLoaded('features', fn () => $this->features->map(fn ($f) => [
                'id' => $f->id,
                'feature' => $f->feature,
                'sort_order' => $f->sort_order,
            ])->sortBy('sort_order')->values()
            ),

            'pricing' => $this->whenLoaded('pricing', fn () => $this->pricing->map(fn ($p) => [
                'id' => $p->id,
                'price' => (float) $p->price,
                'billing_cycle' => $p->billingCycle ? [
                    'id' => $p->billingCycle->id,
                    'slug' => $p->billingCycle->slug,
                    'name' => $p->billingCycle->name,
                    'months' => $p->billingCycle->months,
                ] : null,
            ])
            ),

            'pterodactyl' => $this->when($this->provisioner === 'pterodactyl', fn () => [
                'nest_id' => $this->pterodactyl_nest_id,
                'egg_id' => $this->pterodactyl_egg_id,
                'node_id' => $this->pterodactyl_node_id,
                'limits' => $this->pterodactyl_limits ?? [],
                'feature_limits' => $this->pterodactyl_feature_limits ?? [],
                'environment' => $this->pterodactyl_environment ?? [],
                'docker_image' => $this->pterodactyl_docker_image,
                'startup' => $this->pterodactyl_startup,
            ]),
        ];
    }

    private function shouldIncludeTimestamp(Request $request): bool
    {
        return $request->has('include_timestamps') || $request->is('api/admin/*');
    }
}
