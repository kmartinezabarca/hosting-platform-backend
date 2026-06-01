<?php

namespace App\Domains\Platform\Models;
use App\Models\User;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class EmailAccount extends Model
{
    use HasFactory, SoftDeletes;

    // ── Fillable ──────────────────────────────────────────────────────────────

    protected $fillable = [
        'uuid',
        'service_id',
        'user_id',
        'local_part',
        'domain',
        'quota_mb',
        'status',
        'mailcow_id',
        'last_sync_at',
    ];

    // ── Casts ─────────────────────────────────────────────────────────────────

    protected $casts = [
        'quota_mb'     => 'integer',
        'last_sync_at' => 'datetime',
    ];

    // ── Boot ──────────────────────────────────────────────────────────────────

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // ── Route key ─────────────────────────────────────────────────────────────

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // ── Accessor ──────────────────────────────────────────────────────────────

    /**
     * Dirección completa: local_part@domain
     */
    public function getFullAddressAttribute(): string
    {
        return strtolower($this->local_part) . '@' . strtolower($this->domain);
    }

    protected $appends = ['full_address'];

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function service(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForDomain($query, string $domain)
    {
        return $query->where('domain', strtolower($domain));
    }

    public function scopeForService($query, int $serviceId)
    {
        return $query->where('service_id', $serviceId);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }
}
