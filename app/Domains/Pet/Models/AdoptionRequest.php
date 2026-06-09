<?php

namespace App\Domains\Pet\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdoptionRequest extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table = 'adoption_requests';
    protected $keyType = 'string';

    protected $fillable = [
        'listing_id', 'requester_owner_id', 'requester_name', 'requester_contact',
        'message', 'status', 'ip_address',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(AdoptionListing::class, 'listing_id');
    }
}
