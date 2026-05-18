<?php

namespace App\Models;

use App\Traits\HasUuidColumn;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Backup extends Model
{
    use HasFactory, SoftDeletes, HasUuidColumn;

    protected $fillable = [
        'uuid',
        'name',
        'type',
        'status',
        'user_id',
        'service_id',
        'schedule_id',
        'disk',
        'path',
        'size_bytes',
        'meta',
        'error',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'meta'         => 'array',
        'size_bytes'   => 'integer',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function schedule()
    {
        return $this->belongsTo(BackupSchedule::class, 'schedule_id');
    }

    public function scopeOfType($q, ?string $type)
    {
        return $type ? $q->where('type', $type) : $q;
    }

    public function scopeForUser($q, $userId)
    {
        return $userId ? $q->where('user_id', $userId) : $q;
    }

    public function getSizeHumanAttribute(): string
    {
        $bytes = (int) $this->size_bytes;
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }
}
