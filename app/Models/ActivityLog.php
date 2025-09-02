<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'action', 'service', 'type', 'meta', 'occurred_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function user() {
        return $this->belongsTo(User::class);
    }

    public static function record(
        string $action,
        ?string $service = null,
        ?string $type = null,
        ?array $meta = null,
        ?int $userId = null,
        ?\Illuminate\Support\Carbon $occurredAt = null,
    ): self {
        $type = strtolower((string)($type ?: 'general'));
        return static::create([
            'user_id'     => $userId ?: Auth::id(),
            'action'      => Str::limit($action, 120),
            'service'     => $service,
            'type'        => $type,
            'meta'        => $meta,
            'occurred_at' => $occurredAt ?: now(),
        ]);
    }

}
