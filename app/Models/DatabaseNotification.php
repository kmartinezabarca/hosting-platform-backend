<?php

namespace App\Models;

use App\Traits\HasUuidColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Custom notification model.
 *
 * Extends Model directly (NOT Illuminate\Notifications\DatabaseNotification)
 * to avoid the HasUuids trait that forces UUID as primary key.
 *
 * Schema: id BIGINT (PK) + uuid VARCHAR(36) UNIQUE + all standard columns.
 */
class DatabaseNotification extends Model
{
    use HasUuidColumn;

    protected $table = 'notifications';

    protected $fillable = [
        'uuid',
        'type',
        'notifiable_type',
        'notifiable_id',
        'data',
        'read_at',
    ];

    protected $casts = [
        'data'    => 'array',
        'read_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function notifiable()
    {
        return $this->morphTo();
    }

    // ── Read / Unread helpers ────────────────────────────────────────────────

    public function markAsRead(): void
    {
        if ($this->isUnread()) {
            $this->forceFill(['read_at' => $this->freshTimestamp()])->save();
        }
    }

    public function markAsUnread(): void
    {
        if ($this->isRead()) {
            $this->forceFill(['read_at' => null])->save();
        }
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }
}
