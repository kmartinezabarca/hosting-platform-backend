<?php

namespace App\Models\Pet;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ActivationEvent extends Model
{
    use HasUuids;

    protected $connection = 'roke_pet';
    protected $table = 'activation_events';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'owner_id', 'pet_id', 'event_type', 'source', 'metadata', 'occurred_at',
    ];

    protected $casts = [
        'metadata'    => 'array',
        'occurred_at' => 'datetime',
    ];

    protected $attributes = [
        'metadata' => '{}',
    ];
}
