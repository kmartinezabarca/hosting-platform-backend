<?php

namespace App\Domains\Platform\Models;
use App\Models\User;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Domain extends Model
{
    use HasFactory;

    // ── Fillable ──────────────────────────────────────────────────────────────

    protected $fillable = [
        'uuid',
        'user_id',
        'domain_name',
        'registrar',          // Opcional — registrador donde el cliente tiene el dominio (solo referencia)
        'external_id',
        'status',
        'registration_date',
        'expiration_date',
        'auto_renew',
        'nameservers',
        // 'dns_records' — DEPRECADO. Usar la relación dnsRecords() (tabla dns_records).
        'whois_privacy',
        'ownership_token',
        'ownership_verified',
        'ownership_verified_at',
    ];

    // ── Casts ─────────────────────────────────────────────────────────────────

    protected $casts = [
        'registration_date'     => 'date',
        'expiration_date'       => 'date',
        'auto_renew'            => 'boolean',
        'whois_privacy'         => 'boolean',
        'nameservers'           => 'array',
        // dns_records JSON field es legado; los registros nuevos van en la tabla dns_records (dnsRecords()).
        'ownership_verified'    => 'boolean',
        'ownership_verified_at' => 'datetime',
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

    /**
     * Propietario del dominio.
     */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Certificados SSL emitidos para este dominio.
     */
    public function sslCertificates(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SslCertificate::class);
    }

    /**
     * Certificado SSL activo más reciente.
     */
    public function activeSsl(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(SslCertificate::class)
            ->where('status', 'active')
            ->latestOfMany('valid_until');
    }

    /**
     * Registros DNS relacionales (reemplaza el campo JSON dns_records).
     */
    public function dnsRecords(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DnsRecord::class);
    }

    /**
     * Cuentas de correo empresarial asociadas a este dominio.
     */
    public function emailAccounts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmailAccount::class, 'domain', 'domain_name');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Solo dominios activos. */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /** Solo dominios expirados. */
    public function scopeExpired($query)
    {
        return $query->where('status', 'expired');
    }

    /** Dominios que vencen en los próximos N días. */
    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->where('status', 'active')
            ->whereBetween('expiration_date', [now(), now()->addDays($days)]);
    }

    /** Dominios con renovación automática activada. */
    public function scopeAutoRenew($query)
    {
        return $query->where('auto_renew', true);
    }

    // ── Helpers de estado ─────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired'
            || ($this->expiration_date && $this->expiration_date->isPast());
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isPendingTransfer(): bool
    {
        return $this->status === 'pending_transfer';
    }

    /**
     * Días restantes hasta el vencimiento (negativo = ya venció).
     */
    public function daysUntilExpiration(): ?int
    {
        if (! $this->expiration_date) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->expiration_date, false);
    }

    /**
     * ¿El dominio vence en menos de $days días?
     */
    public function isExpiringSoon(int $days = 30): bool
    {
        $remaining = $this->daysUntilExpiration();

        return $remaining !== null && $remaining >= 0 && $remaining <= $days;
    }

    /**
     * Devuelve el color de estado para la UI.
     */
    public function getStatusColor(): string
    {
        return match ($this->status) {
            'active'           => 'green',
            'expired'          => 'red',
            'suspended'        => 'orange',
            'pending_transfer' => 'yellow',
            'cancelled'        => 'gray',
            default            => 'gray',
        };
    }

    /**
     * Etiqueta legible del estado.
     */
    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'active'           => 'Activo',
            'expired'          => 'Expirado',
            'suspended'        => 'Suspendido',
            'pending_transfer' => 'Transferencia pendiente',
            'cancelled'        => 'Cancelado',
            default            => 'Desconocido',
        };
    }

    /**
     * Nombre de dominio limpio sin www.
     */
    public function getBaseDomainAttribute(): string
    {
        return preg_replace('/^www\./', '', strtolower($this->domain_name));
    }

    /**
     * TLD del dominio (ej: "com", "mx", "net").
     */
    public function getTldAttribute(): string
    {
        $parts = explode('.', $this->domain_name);

        return strtolower(end($parts));
    }
}
