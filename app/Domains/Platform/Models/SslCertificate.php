<?php

namespace App\Domains\Platform\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SslCertificate extends Model
{
    use HasFactory;

    // ── Fillable ──────────────────────────────────────────────────────────────

    protected $fillable = [
        'uuid',
        'service_id',
        'domain',
        'issuer',
        'type',
        'status',
        'valid_from',
        'valid_until',
        'auto_renew',
        'force_https',
        'is_wildcard',
        'meta',
        'last_checked_at',
        'expiry_notified_at',
    ];

    // ── Casts ─────────────────────────────────────────────────────────────────

    protected $casts = [
        'valid_from'          => 'datetime',
        'valid_until'         => 'datetime',
        'auto_renew'          => 'boolean',
        'force_https'         => 'boolean',
        'is_wildcard'         => 'boolean',
        'meta'                => 'array',
        'last_checked_at'     => 'datetime',
        'expiry_notified_at'  => 'datetime',
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

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function service(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->whereIn('status', ['active', 'expiring_soon'])
            ->whereBetween('valid_until', [now(), now()->addDays($days)]);
    }

    public function scopeAutoRenew($query)
    {
        return $query->where('auto_renew', true);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired'
            || ($this->valid_until && $this->valid_until->isPast());
    }

    /**
     * Días restantes antes del vencimiento (negativo = ya venció).
     */
    public function daysRemaining(): ?int
    {
        if (! $this->valid_until) {
            return null;
        }

        return (int) now()->diffInDays($this->valid_until, false);
    }

    /**
     * Actualiza el estado del cert según los días restantes.
     * Llamar tras fetchCertInfo() en HostingController.
     */
    public function syncStatusFromDays(): void
    {
        $days = $this->daysRemaining();

        if ($days === null) {
            return;
        }

        $this->status = match (true) {
            $days <= 0  => 'expired',
            $days <= 30 => 'expiring_soon',
            default     => 'active',
        };
    }

    /**
     * Rellena el modelo desde el array devuelto por HostingController::fetchCertInfo().
     */
    public function fillFromCertInfo(array $info): static
    {
        $this->issuer     = $info['issuer']     ?? null;
        $this->valid_from = $info['valid_from']  ? \Carbon\Carbon::parse($info['valid_from'])  : null;
        $this->valid_until = $info['valid_to']   ? \Carbon\Carbon::parse($info['valid_to'])    : null;
        $this->last_checked_at = now();

        $this->syncStatusFromDays();

        return $this;
    }
}
