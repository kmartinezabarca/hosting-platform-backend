<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemStatusResource extends JsonResource
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
                'serviceName' => $this->service_name,
                'status' => $this->status,
                'message' => $this->message,
                'lastUpdated' => $this->last_updated->format('Y-m-d H:i:s'),
                'createdAt' => $this->created_at->format('Y-m-d H:i:s'),
                'updatedAt' => $this->updated_at->format('Y-m-d H:i:s'),
            ];
    }
}
