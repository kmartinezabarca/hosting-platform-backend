<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSession extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'sanctum_token_id',
        'laravel_session_id',
        'ip_address',
        'user_agent',
        'device',
        'platform',
        'browser',
        'country',
        'region',
        'city',
        'login_at',
        'last_activity',
        'logout_at',
        'meta',
    ];

    protected $casts = [
        'login_at'      => 'datetime',
        'last_activity' => 'datetime',
        'logout_at'     => 'datetime',
        'meta'          => 'array',
    ];
}
