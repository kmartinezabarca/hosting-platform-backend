<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameServerPing extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'service_id',
        'ping_ms',
        'is_online',
        'players',
        'sampled_at',
    ];

    protected $casts = [
        'ping_ms'    => 'integer',
        'is_online'  => 'boolean',
        'players'    => 'integer',
        'sampled_at' => 'datetime',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
