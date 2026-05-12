<?php

namespace App\Models;

use App\Traits\HasUuidColumn;
use Illuminate\Database\Eloquent\Model;

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
        'archived_at',
    ];

    protected $casts = [
        'data'        => 'array',
        'read_at'     => 'datetime',
        'archived_at' => 'datetime',
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

    // ── Archive helpers ──────────────────────────────────────────────────────

    public function archive(): void
    {
        if (!$this->isArchived()) {
            $this->forceFill(['archived_at' => $this->freshTimestamp()])->save();
        }
    }

    public function unarchive(): void
    {
        if ($this->isArchived()) {
            $this->forceFill(['archived_at' => null])->save();
        }
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
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

    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeNotArchived($query)
    {
        return $query->whereNull('archived_at');
    }
}
